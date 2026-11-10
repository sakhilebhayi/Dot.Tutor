<?php

namespace App\Domain\Integration\Adapters\Learning;

use App\Domain\Learning\Contracts\Seams\TutorStanding;
use App\Domain\Learning\Seams\DatabaseTutorStanding;
use App\Domain\Marketplace\Contracts\TutorDirectory;
use App\Models\User;

/**
 * "Approved tutor" as the marketplace's directory says it (approved profile, with its verification); whether a tutor
 * has taught a learner stays the learning domain's own read of confirmed or completed bookings.
 */
class DirectoryTutorStanding implements TutorStanding
{
    public function __construct(private readonly TutorDirectory $directory, private readonly DatabaseTutorStanding $sessions) {}

    public function isApprovedTutor(User $user): bool
    {
        $profileId = $user->tutorProfile()->value('id');

        return $profileId !== null && $this->directory->find((int) $profileId) !== null;
    }

    public function hasTaught(User $tutor, User $learner): bool
    {
        return $this->sessions->hasTaught($tutor, $learner);
    }
}
