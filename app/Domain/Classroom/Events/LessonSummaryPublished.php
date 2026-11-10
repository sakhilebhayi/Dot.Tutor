<?php

namespace App\Domain\Classroom\Events;

use App\Domain\Classroom\Models\Classroom;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Emitted when the tutor published the post-lesson summary to the learners (and their guardians). The classroom sends no notifications itself: the comms domain listens (D-812).
 */
class LessonSummaryPublished
{
    use Dispatchable;

    /** @param array<string, mixed> $context */
    public function __construct(public readonly Classroom $classroom, public readonly array $context = []) {}
}
