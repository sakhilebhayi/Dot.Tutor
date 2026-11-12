<?php

namespace App\Domain\Classroom\Events;

use App\Domain\Classroom\Models\Classroom;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Emitted when the tutor ended the lesson, or the room closed after its grace time. The classroom sends no notifications itself: the comms domain listens (D-812).
 */
class ClassroomEnded
{
    use Dispatchable;

    /** @param array<string, mixed> $context */
    public function __construct(public readonly Classroom $classroom, public readonly array $context = []) {}
}
