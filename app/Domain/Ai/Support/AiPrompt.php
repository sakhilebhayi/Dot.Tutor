<?php

namespace App\Domain\Ai\Support;

/**
 * One request to the model: a system prompt (the guardrails) and one user turn made of content blocks.
 * Blocks: ['type' => 'text', 'text' => ...], ['type' => 'document', 'media_type' => 'application/pdf', 'data' => base64]
 * or ['type' => 'image', 'media_type' => 'image/png', 'data' => base64]. A schema asks for structured JSON output.
 */
final readonly class AiPrompt
{
    /**
     * @param  list<array<string, string>>  $blocks
     * @param  array<string, mixed>|null  $schema  JSON schema for structured output
     */
    public function __construct(
        public string $system,
        public array $blocks,
        public string $tier = 'standard',
        public ?array $schema = null,
        public ?string $pseudonymousUser = null,
    ) {}

    /** The text parts only, as logged (documents and images are logged as a reference, never as bytes). */
    public function loggableText(): string
    {
        return implode("\n\n", array_map(
            fn (array $block) => $block['type'] === 'text' ? $block['text'] : '['.$block['type'].' attached: '.($block['label'] ?? $block['media_type'] ?? '').']',
            $this->blocks,
        ));
    }
}
