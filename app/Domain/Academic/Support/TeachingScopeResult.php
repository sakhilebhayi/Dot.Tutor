<?php

namespace App\Domain\Academic\Support;

final readonly class TeachingScopeResult
{
    /**
     * @param  list<array{subject_id: int, level_id: int, offering_id: int}>  $valid
     * @param  list<array{subject_id: int, level_id: int, reason: string}>  $invalid
     */
    public function __construct(public array $valid, public array $invalid) {}

    public function passes(): bool
    {
        return $this->invalid === [];
    }
}
