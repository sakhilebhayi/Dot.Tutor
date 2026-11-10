<?php

namespace App\Domain\Integration\Adapters\Marketplace;

use App\Domain\Integration\Support\SafetyAges;
use App\Domain\Marketplace\Contracts\LearnerAgePort;
use App\Models\User;

class SafetyLearnerAge implements LearnerAgePort
{
    public function __construct(private readonly SafetyAges $ages) {}

    public function isMinor(User $learner): ?bool
    {
        return $this->ages->knownBand($learner)?->isMinor();
    }
}
