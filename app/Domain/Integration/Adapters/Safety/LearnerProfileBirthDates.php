<?php

namespace App\Domain\Integration\Adapters\Safety;

use App\Domain\Academic\Services\LearnerProfiles;
use App\Domain\Safety\Contracts\BirthDateSource;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The learner's self-declared birth month from the academic learner profile (the conservative last day of the
 * month, so a child is never read as older). A guardian-confirmed or operator-verified date in safety outranks it.
 */
class LearnerProfileBirthDates implements BirthDateSource
{
    public function __construct(private readonly LearnerProfiles $profiles) {}

    public function birthDateFor(User $user): ?CarbonImmutable
    {
        return $this->profiles->birthDateFor($user);
    }
}
