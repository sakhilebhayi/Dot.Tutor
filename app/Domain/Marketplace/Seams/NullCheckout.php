<?php

namespace App\Domain\Marketplace\Seams;

use App\Domain\Marketplace\Contracts\CheckoutPort;
use App\Domain\Marketplace\Support\CheckoutResult;
use App\Models\User;

/** No payment provider connected: never pretends to charge. */
class NullCheckout implements CheckoutPort
{
    public function isConfigured(): bool
    {
        return false;
    }

    public function start(User $payer, array $purchasable, string $idempotencyKey): CheckoutResult
    {
        return CheckoutResult::unconfigured();
    }
}
