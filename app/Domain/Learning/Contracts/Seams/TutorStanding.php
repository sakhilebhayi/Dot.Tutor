<?php

namespace App\Domain\Learning\Contracts\Seams;

use App\Models\User;

/** The learning side of Marketplace\Contracts\TutorDirectory: is this user an approved tutor, and whom do they teach? */
interface TutorStanding
{
    public function isApprovedTutor(User $user): bool;

    /** Does the tutor have a confirmed or completed session with this learner? */
    public function hasTaught(User $tutor, User $learner): bool;
}
