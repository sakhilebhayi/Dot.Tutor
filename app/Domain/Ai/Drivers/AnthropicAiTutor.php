<?php

namespace App\Domain\Ai\Drivers;

use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\NotFoundException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;
use App\Domain\Ai\Contracts\AiTutor;
use App\Domain\Ai\Support\AiOutcome;
use App\Domain\Ai\Support\AiPrompt;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Claude through the official Anthropic PHP SDK (claude-api skill, D-901): one non-streaming Messages API call per
 * request. Opus 5.5 always uses adaptive thinking; effort (config ai.effort) is the depth control. Structured output
 * (output_config.format json_schema) is used when the caller gives a schema. The server-side refusal fallback is on
 * by default (beta server-side-fallback-2026-07-01, fallbacks "default"), so the model that actually answered is
 * read from the response, never assumed. No sampling parameters are sent (API version 2026-10-21 rejects them).
 */
class AnthropicAiTutor implements AiTutor
{
    public function __construct(private readonly AnthropicClientFactory $clients) {}

    public function configured(): bool
    {
        return (bool) config('ai.enabled') && filled(config('ai.anthropic.api_key'));
    }

    public function modelFor(string $tier): ?string
    {
        if (! $this->configured()) {
            return null;
        }

        return (string) ($tier === 'light' ? config('ai.anthropic.model_light') : config('ai.anthropic.model'));
    }

    public function generate(AiPrompt $prompt): AiOutcome
    {
        $model = $this->modelFor($prompt->tier);
        if ($model === null) {
            return AiOutcome::notConfigured();
        }

        $outputConfig = ['effort' => (string) config('ai.effort.'.$prompt->tier, 'medium')];
        if ($prompt->schema !== null) {
            $outputConfig['format'] = ['type' => 'json_schema', 'schema' => $prompt->schema];
        }
        $fallback = (bool) config('ai.anthropic.refusal_fallback', true);

        try {
            $message = $this->clients->make((string) config('ai.anthropic.api_key'))->beta->messages->create(
                maxTokens: (int) config('ai.max_tokens.'.$prompt->tier, 16000),
                messages: [['role' => 'user', 'content' => $this->content($prompt)]],
                model: $model,
                fallbacks: $fallback ? 'default' : null,
                metadata: $prompt->pseudonymousUser ? ['userID' => $prompt->pseudonymousUser] : null,
                outputConfig: $outputConfig,
                system: $prompt->system,
                betas: $fallback ? ['server-side-fallback-2026-07-01'] : null,
            );
        } catch (AuthenticationException|PermissionDeniedException $e) {
            Log::critical('AI: the Anthropic API key was refused.', ['status' => $e->getCode()]);

            return AiOutcome::failed('provider_auth', $model);
        } catch (NotFoundException $e) {
            Log::error('AI: model not found.', ['model' => $model]);

            return AiOutcome::failed('provider_model', $model);
        } catch (RateLimitException $e) {
            return AiOutcome::failed('provider_busy', $model);
        } catch (BadRequestException $e) {
            Log::error('AI: request rejected by the API.', ['type' => $e->type?->value]);

            return AiOutcome::failed('provider_rejected', $model);
        } catch (APIStatusException $e) {
            Log::warning('AI: API error.', ['type' => $e->type?->value, 'status' => $e->getCode()]);

            return AiOutcome::failed('provider_error', $model);
        } catch (APIConnectionException $e) {
            return AiOutcome::failed('provider_unreachable', $model);
        } catch (APIException $e) {
            Log::warning('AI: SDK error.', ['class' => $e::class]);

            return AiOutcome::failed('provider_error', $model);
        } catch (Throwable $e) {
            Log::error('AI: unexpected failure.', ['class' => $e::class]);

            return AiOutcome::failed('provider_error', $model);
        }

        $text = '';
        foreach ($message->content as $block) {
            if (($block->type ?? null) === 'text') {
                $text .= $block->text;
            }
        }

        $usage = $message->usage;
        $base = [
            'model' => $message->model,
            'inputTokens' => (int) $usage->inputTokens + (int) ($usage->cacheCreationInputTokens ?? 0) + (int) ($usage->cacheReadInputTokens ?? 0),
            'outputTokens' => (int) $usage->outputTokens,
            'stopReason' => $message->stopReason,
        ];

        if ($message->stopReason === 'refusal') {
            return new AiOutcome('refused_by_model', ...$base, refusalCategory: $message->stopDetails?->category);
        }
        if ($message->stopReason === 'max_tokens') {
            return new AiOutcome('incomplete', $text, ...$base);
        }

        $data = null;
        if ($prompt->schema !== null) {
            $data = json_decode($text, true);
            if (! is_array($data)) {
                return new AiOutcome('failed', '', null, ...$base, errorCode: 'malformed_output');
            }
        }

        return new AiOutcome('ok', $text, $data, ...$base);
    }

    /** @return list<array<string, mixed>> */
    private function content(AiPrompt $prompt): array
    {
        return array_map(fn (array $block) => match ($block['type']) {
            'document' => ['type' => 'document', 'source' => ['type' => 'base64', 'mediaType' => 'application/pdf', 'data' => $block['data']]],
            'image' => ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => $block['media_type'], 'data' => $block['data']]],
            default => ['type' => 'text', 'text' => $block['text']],
        }, $prompt->blocks);
    }
}
