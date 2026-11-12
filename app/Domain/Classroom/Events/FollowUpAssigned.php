<?php

namespace App\Domain\Classroom\Events;

use App\Domain\Classroom\Models\Classroom;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Emitted when the tutor assigned follow-up work after the lesson (learning's assessment assignment). The classroom sends no notifications itself: the comms domain listens (D-812).
 */
class FollowUpAssigned
{
    use Dispatchable;

    /** @param array<string, mixed> $context */
    public function __construct(public readonly Classroom $classroom, public readonly array $context = []) {}
}
