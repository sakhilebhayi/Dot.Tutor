<?php

namespace App\Domain\Integration\Adapters\Safety;

use App\Domain\Academic\Models\LearnerProfile;
use App\Domain\Academic\Services\LearnerProfiles;
use App\Domain\Safety\Contracts\BatchBirthDateSource;
use App\Domain\Safety\Contracts\BirthDateSource;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The learner's self-declared birth month from the academic learner profile (the conservative last day of the
 * month, so a child is never read as older). A guardian-confirmed or operator-verified date in safety outranks it.
 */
class LearnerProfileBirthDates implements BatchBirthDateSource, BirthDateSource
{
    public function __construct(private readonly LearnerProfiles $profiles) {}

    public function birthDateFor(User $user): ?CarbonImmutable
    {
        return $this->profiles->birthDateFor($user);
    }

    public function birthDatesFor(array $userIds): array
    {
        return LearnerProfile::query()->whereIn('user_id', $userIds)->get()
            ->mapWithKeys(fn (LearnerProfile $profile) => [(int) $profile->user_id => $profile->ageBandBirthDate()])
            ->all();
    }
}
