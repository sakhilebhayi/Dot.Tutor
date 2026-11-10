<?php

namespace App\Domain\Marketplace\Seams;

use App\Domain\Marketplace\Contracts\LearnerAgePort;
use App\Models\User;

/** No date of birth is held yet, so age is unknown. */
class NullLearnerAge implements LearnerAgePort
{
    public function isMinor(User $learner): ?bool
    {
        return null;
    }
}
