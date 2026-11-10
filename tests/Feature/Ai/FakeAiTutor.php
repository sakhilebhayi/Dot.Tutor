<?php

namespace Tests\Feature\Ai;

use App\Domain\Ai\Contracts\AiTutor;
use App\Domain\Ai\Support\AiOutcome;
use App\Domain\Ai\Support\AiPrompt;

/** A configured AI that never leaves the process: records every prompt and answers from a queue. */
class FakeAiTutor implements AiTutor
{
    /** @var list<AiPrompt> */
    public array $prompts = [];

    /** @var list<AiOutcome> */
    private array $queue = [];

    public function queue(AiOutcome ...$outcomes): self
    {
        array_push($this->queue, ...$outcomes);

        return $this;
    }

    /** @param  array<string, mixed>|null  $data */
    public static function ok(string $text, ?array $data = null, int $in = 1000, int $out = 500): AiOutcome
    {
        return new AiOutcome('ok', $data !== null ? json_encode($data) : $text, $data, 'claude-opus-5-5', $in, $out, 'end_turn');
    }

    public function configured(): bool
    {
        return true;
    }

    public function modelFor(string $tier): ?string
    {
        return 'claude-opus-5-5';
    }

    public function generate(AiPrompt $prompt): AiOutcome
    {
        $this->prompts[] = $prompt;

        return array_shift($this->queue) ?? self::ok('A guided explanation. Try the next step yourself.');
    }

    public function lastSystem(): string
    {
        return end($this->prompts)->system;
    }

    public function lastText(): string
    {
        return end($this->prompts)->loggableText();
    }
}
