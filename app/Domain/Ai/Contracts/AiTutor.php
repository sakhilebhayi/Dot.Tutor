<?php

namespace App\Domain\Ai\Contracts;

use App\Domain\Ai\Support\AiOutcome;
use App\Domain\Ai\Support\AiPrompt;

/**
 * The one door to a language model (architecture section 4). Two drivers: AnthropicAiTutor (official Anthropic PHP
 * SDK, Messages API) and NullAiTutor (nothing configured: every call answers not_configured and nothing is sent).
 * Callers never call a driver directly: AiGateway applies the guardrails, limits and logging around it.
 */
interface AiTutor
{
    /** True only when AI is switched on and a key is present. */
    public function configured(): bool;

    /** The model id a tier would use ("standard" or "light"), or null when not configured. */
    public function modelFor(string $tier): ?string;

    public function generate(AiPrompt $prompt): AiOutcome;
}
