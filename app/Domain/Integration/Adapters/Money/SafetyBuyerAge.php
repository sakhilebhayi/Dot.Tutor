<?php

namespace App\Domain\Integration\Adapters\Money;

use App\Domain\Integration\Support\SafetyAges;
use App\Domain\Money\Contracts\BuyerAge;
use App\Models\User;

class SafetyBuyerAge implements BuyerAge
{
    public function __construct(private readonly SafetyAges $ages) {}

    public function bandFor(User $user): ?string
    {
        return $this->ages->knownBand($user)?->value;
    }
}
