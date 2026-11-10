<?php

namespace App\Domain\Marketplace\Contracts;

use App\Models\User;

/**
 * Is this learner a minor (under 18)? Owned by safety (AgeBand from the
 * learner profile's date of birth). Null means "not known".
 */
interface LearnerAgePort
{
    public function isMinor(User $learner): ?bool;
}
