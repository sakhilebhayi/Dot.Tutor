<?php

namespace App\Domain\Ai\Drivers;

use App\Domain\Ai\Contracts\AiTutor;
use App\Domain\Ai\Support\AiOutcome;
use App\Domain\Ai\Support\AiPrompt;

/** No AI configured: nothing is sent, every screen says so. */
class NullAiTutor implements AiTutor
{
    public function configured(): bool
    {
        return false;
    }

    public function modelFor(string $tier): ?string
    {
        return null;
    }

    public function generate(AiPrompt $prompt): AiOutcome
    {
        return AiOutcome::notConfigured();
    }
}
