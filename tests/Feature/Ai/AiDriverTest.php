<?php

namespace Tests\Feature\Ai;

use App\Domain\Ai\Contracts\AiTutor;
use App\Domain\Ai\Drivers\AnthropicAiTutor;
use App\Domain\Ai\Drivers\AnthropicClientFactory;
use App\Domain\Ai\Drivers\NullAiTutor;
use App\Domain\Ai\Models\AiInteraction;
use App\Domain\Ai\Support\AiPrompt;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Tests\TestCase;

/** The two drivers. The Anthropic driver is exercised through the real SDK with a fake PSR-18 transport (no network). */
class AiDriverTest extends TestCase
{
    use AiFixtures;
    use RefreshDatabase;

    private function transport(int $status, array $body): ClientInterface
    {
        return new class($status, $body) implements ClientInterface
        {
            /** @var list<RequestInterface> */
            public array $requests = [];

            public function __construct(private int $status, private array $body) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->requests[] = $request;

                return new Response($this->status, ['content-type' => 'application/json'], json_encode($this->body));
            }
        };
    }

    private function message(array $overrides = []): array
    {
        return array_merge([
            'id' => 'msg_test', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5',
            'content' => [['type' => 'thinking', 'thinking' => '', 'signature' => 'sig'], ['type' => 'text', 'text' => 'Guidance text']],
            'stop_reason' => 'end_turn', 'stop_sequence' => null,
            'usage' => ['input_tokens' => 120, 'output_tokens' => 80],
        ], $overrides);
    }

    private function driver(ClientInterface $transport): AnthropicAiTutor
    {
        config(['ai.enabled' => true, 'ai.anthropic.api_key' => 'sk-test-not-real']);

        return new AnthropicAiTutor(new AnthropicClientFactory($transport));
    }

    public function test_ai_is_off_by_default_and_the_null_driver_sends_nothing(): void
    {
        $this->assertFalse(config('ai.enabled'));
        $this->assertInstanceOf(NullAiTutor::class, app(AiTutor::class));

        $this->actingAs($this->adult());
        $this->getJson('/api/tutor/ai')->assertOk()->assertJsonPath('data.configured', false)->assertJsonPath('data.model', null);
        $this->postJson('/api/tutor/ai/ask', ['task' => 'explain', 'text' => 'What is photosynthesis?'])
            ->assertStatus(503)->assertJsonPath('error', 'ai_not_configured');
        $this->assertSame(0, AiInteraction::count(), 'Nothing happened, so nothing is logged.');

        $this->get('/ai')->assertOk()->assertSee('The AI assistant is not configured yet');
    }

    public function test_the_provider_binds_the_anthropic_driver_only_with_a_switch_and_a_key(): void
    {
        config(['ai.enabled' => true, 'ai.anthropic.api_key' => '']);
        $this->app->forgetInstance(AiTutor::class);
        $this->assertInstanceOf(NullAiTutor::class, app(AiTutor::class));

        config(['ai.anthropic.api_key' => 'sk-test-not-real']);
        $this->assertInstanceOf(AnthropicAiTutor::class, app(AiTutor::class));
        $this->assertSame('claude-opus-5-5', app(AiTutor::class)->modelFor('standard'));
    }

    public function test_the_anthropic_driver_sends_one_messages_request_with_the_documented_shape(): void
    {
        $transport = $this->transport(200, $this->message());
        $outcome = $this->driver($transport)->generate(new AiPrompt('System rules', [['type' => 'text', 'text' => 'Explain fractions']], pseudonymousUser: 'abc123'));

        $this->assertSame('ok', $outcome->status);
        $this->assertSame('Guidance text', $outcome->text);
        $this->assertSame(['claude-opus-5-5', 120, 80], [$outcome->model, $outcome->inputTokens, $outcome->outputTokens]);

        $this->assertCount(1, $transport->requests);
        $request = $transport->requests[0];
        $this->assertSame('https://api.anthropic.com/v1/messages', (string) $request->getUri());
        $this->assertSame('server-side-fallback-2026-07-01', $request->getHeaderLine('anthropic-beta'));
        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame('claude-opus-5-5', $body['model']);
        $this->assertSame('default', $body['fallbacks']);
        $this->assertSame('medium', $body['output_config']['effort']);
        $this->assertSame('System rules', $body['system']);
        $this->assertSame(['user_id' => 'abc123'], $body['metadata']);
        $this->assertArrayNotHasKey('temperature', $body);
        $this->assertArrayNotHasKey('thinking', $body);
    }

    public function test_structured_output_refusal_truncation_and_errors_are_reported_honestly(): void
    {
        $schema = ['type' => 'object', 'properties' => ['a' => ['type' => 'integer']], 'required' => ['a'], 'additionalProperties' => false];
        $prompt = new AiPrompt('s', [['type' => 'text', 'text' => 't']], 'light', $schema);

        $transport = $this->transport(200, $this->message(['content' => [['type' => 'text', 'text' => '{"a":3}']]]));
        $outcome = $this->driver($transport)->generate($prompt);
        $this->assertSame(['a' => 3], $outcome->data);
        $body = json_decode((string) $transport->requests[0]->getBody(), true);
        $this->assertSame('json_schema', $body['output_config']['format']['type']);
        $this->assertSame('low', $body['output_config']['effort']);

        $refused = $this->driver($this->transport(200, $this->message(['content' => [], 'stop_reason' => 'refusal', 'stop_details' => ['type' => 'refusal', 'category' => 'cyber', 'explanation' => null]])))->generate($prompt);
        $this->assertSame(['refused_by_model', 'cyber'], [$refused->status, $refused->refusalCategory]);

        $cut = $this->driver($this->transport(200, $this->message(['stop_reason' => 'max_tokens'])))->generate(new AiPrompt('s', [['type' => 'text', 'text' => 't']]));
        $this->assertSame('incomplete', $cut->status);

        config(['ai.anthropic.max_retries' => 0]);
        $auth = $this->driver($this->transport(401, ['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key']]))->generate($prompt);
        $this->assertSame(['failed', 'provider_auth'], [$auth->status, $auth->errorCode]);
    }
}
