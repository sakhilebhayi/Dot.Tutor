<?php

namespace App\Domain\Classroom\Events;

use App\Domain\Classroom\Models\Classroom;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Emitted when the first person joined the room. The classroom sends no notifications itself: the comms domain listens (D-812).
 */
class ClassroomOpened
{
    use Dispatchable;

    /** @param array<string, mixed> $context */
    public function __construct(public readonly Classroom $classroom, public readonly array $context = []) {}
}
