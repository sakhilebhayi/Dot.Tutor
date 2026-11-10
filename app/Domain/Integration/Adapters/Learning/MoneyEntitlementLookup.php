<?php

namespace App\Domain\Integration\Adapters\Learning;

use App\Domain\Integration\Support\MoneyTypes;
use App\Domain\Learning\Contracts\Seams\EntitlementLookup;
use App\Domain\Money\Contracts\EntitlementCheck;
use App\Models\User;

/** Paid, gifted or bundled access from money_entitlements, re-read on every request (a refund ends access at once). */
class MoneyEntitlementLookup implements EntitlementLookup
{
    public function __construct(private readonly EntitlementCheck $entitlements) {}

    public function owns(User $user, string $type, int $id): bool
    {
        return $this->entitlements->owns($user->id, MoneyTypes::learningToMoney($type), $id);
    }
}
