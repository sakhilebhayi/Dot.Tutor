<?php

namespace App\Domain\Learning\Policies;

use App\Domain\Learning\Models\Course;
use App\Models\User;

class CoursePolicy
{
    /** Edit the draft, structure, pricing; publish; see learners' progress. */
    public function manage(User $user, Course $course): bool
    {
        return $course->owner_id === $user->id;
    }

    /** Read draft content (the owner, or an operator checking a course). */
    public function viewDraft(User $user, Course $course): bool
    {
        return $course->owner_id === $user->id || (bool) $user->is_platform_operator;
    }
}
