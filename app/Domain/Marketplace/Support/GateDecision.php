<?php

namespace App\Domain\Marketplace\Support;

final readonly class GateDecision
{
    /** @param  list<string>  $reasons */
    public function __construct(public bool $allowed, public array $reasons = []) {}

    public static function allow(string ...$reasons): self
    {
        return new self(true, array_values($reasons));
    }

    public static function deny(string ...$reasons): self
    {
        return new self(false, array_values($reasons));
    }
}
