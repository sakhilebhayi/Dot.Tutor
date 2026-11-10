<?php

namespace App\Domain\Learning\Seams;

use App\Domain\Learning\Contracts\Seams\TutorStanding;
use App\Enums\TutorProfileStatus;
use App\Models\TutorSession;
use App\Models\User;

/** Reads the existing tutor profile and session tables (read-only). The marketplace may rebind it to TutorDirectory. */
class DatabaseTutorStanding implements TutorStanding
{
    public function isApprovedTutor(User $user): bool
    {
        return $user->tutorProfile()->where('status', TutorProfileStatus::Approved->value)->exists();
    }

    public function hasTaught(User $tutor, User $learner): bool
    {
        $profileId = $tutor->tutorProfile()->value('id');

        return $profileId !== null && TutorSession::query()
            ->where('tutor_profile_id', $profileId)
            ->where('student_id', $learner->id)
            ->whereIn('status', ['confirmed', 'completed'])
            ->exists();
    }
}
