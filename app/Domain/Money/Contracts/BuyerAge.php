<?php

namespace App\Domain\Money\Contracts;

use App\Models\User;

/**
 * A buyer's age band as money needs it: child (under 13), teen (13 to 17), adult (18+), or null when unknown.
 * The safety domain's AgeBand is bound here by the integration agent.
 */
interface BuyerAge
{
    public function bandFor(User $user): ?string;
}
