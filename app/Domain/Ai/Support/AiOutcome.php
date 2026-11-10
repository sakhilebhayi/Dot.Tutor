<?php

namespace App\Domain\Ai\Support;

/**
 * What a driver answered. Statuses:
 * - ok: text (and data when a schema was asked for)
 * - incomplete: the answer was cut off at the token limit (text is partial and is shown as such)
 * - refused_by_model: the model declined (refusal stop reason)
 * - failed: the API could not be reached or answered an error (errorCode says which, never the raw message)
 * - not_configured: nothing was sent
 */
final readonly class AiOutcome
{
    /** @param  array<string, mixed>|null  $data */
    public function __construct(
        public string $status,
        public string $text = '',
        public ?array $data = null,
        public ?string $model = null,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public ?string $stopReason = null,
        public ?string $errorCode = null,
        public ?string $refusalCategory = null,
    ) {}

    public static function notConfigured(): self
    {
        return new self('not_configured', errorCode: 'not_configured');
    }

    public static function failed(string $code, ?string $model = null): self
    {
        return new self('failed', model: $model, errorCode: $code);
    }

    public function ok(): bool
    {
        return $this->status === 'ok';
    }

    public function reachedModel(): bool
    {
        return in_array($this->status, ['ok', 'incomplete', 'refused_by_model'], true);
    }
}
