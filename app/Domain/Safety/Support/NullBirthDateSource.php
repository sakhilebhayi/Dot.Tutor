<?php

namespace App\Domain\Safety\Support;

use App\Domain\Safety\Contracts\BirthDateSource;
use App\Models\User;
use Carbon\CarbonImmutable;

/** Honest default until the academic learner profile is bound: it does not know anyone's date of birth. */
class NullBirthDateSource implements BirthDateSource
{
    public function birthDateFor(User $user): ?CarbonImmutable
    {
        return null;
    }
}
