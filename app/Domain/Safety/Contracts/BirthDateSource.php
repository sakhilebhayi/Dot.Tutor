<?php

namespace App\Domain\Safety\Contracts;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Where a learner's self-declared date of birth lives (the academic agent's learner profile). The safety domain
 * reads it through this seam; the default binding knows nothing and returns null. A date of birth recorded in
 * safety_age_records (guardian-confirmed or operator-verified) outranks this source.
 */
interface BirthDateSource
{
    public function birthDateFor(User $user): ?CarbonImmutable;
}
