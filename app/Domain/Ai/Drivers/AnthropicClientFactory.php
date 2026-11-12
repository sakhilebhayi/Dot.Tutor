<?php

namespace App\Domain\Ai\Drivers;

use Anthropic\Client;
use Psr\Http\Client\ClientInterface;

/**
 * Builds the official Anthropic PHP SDK client from config. The key is always passed explicitly, so the SDK never
 * falls back to environment variables, OAuth profiles or workload identity on the host. OpenTelemetry spans are off
 * (they could carry learners' text). Tests give it a fake PSR-18 transporter so no request leaves the process.
 */
class AnthropicClientFactory
{
    public function __construct(private readonly ?ClientInterface $transporter = null) {}

    public function make(string $apiKey): Client
    {
        $options = [
            'timeout' => (float) config('ai.anthropic.timeout_seconds', 60),
            'maxRetries' => (int) config('ai.anthropic.max_retries', 1),
        ];
        if ($this->transporter !== null) {
            $options['transporter'] = $this->transporter;
        }

        return new Client(
            apiKey: $apiKey,
            baseUrl: (string) config('ai.anthropic.base_url', 'https://api.anthropic.com'),
            requestOptions: $options,
            openTelemetry: false,
        );
    }
}
