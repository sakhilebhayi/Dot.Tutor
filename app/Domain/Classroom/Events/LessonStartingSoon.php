<?php

namespace App\Domain\Classroom\Events;

use App\Domain\Classroom\Models\Classroom;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Emitted when a confirmed lesson starts within the reminder window (emitted once per lesson by classroom:sweep); comms sends the reminders. The classroom sends no notifications itself: the comms domain listens (D-812).
 */
class LessonStartingSoon
{
    use Dispatchable;

    /** @param array<string, mixed> $context */
    public function __construct(public readonly Classroom $classroom, public readonly array $context = []) {}
}
