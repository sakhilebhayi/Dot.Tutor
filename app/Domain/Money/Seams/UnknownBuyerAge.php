<?php

namespace App\Domain\Money\Seams;

use App\Domain\Money\Contracts\BuyerAge;
use App\Models\User;

/**
 * Default until the safety domain's AgeBand is bound: the money domain holds no date of birth, so it does
 * not know anyone's age and says so.
 */
class UnknownBuyerAge implements BuyerAge
{
    public function bandFor(User $user): ?string
    {
        return null;
    }
}
