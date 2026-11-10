<?php

namespace App\Domain\Learning\Support;

final readonly class GateDecision
{
    public function __construct(public bool $allowed, public ?string $reason = null) {}

    public static function allow(): self
    {
        return new self(true);
    }

    public static function deny(string $reason): self
    {
        return new self(false, $reason);
    }
}
