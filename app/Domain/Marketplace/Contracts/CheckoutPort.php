<?php

namespace App\Domain\Marketplace\Contracts;

use App\Domain\Marketplace\Support\CheckoutResult;
use App\Models\User;

/**
 * Taking payment for a booking, seat or package. Owned by money as
 * Money\Contracts\Checkout. When a payment is verified server-side, the money
 * side calls App\Domain\Marketplace\Services\PaymentCallbacks.
 */
interface CheckoutPort
{
    public function isConfigured(): bool;

    /**
     * @param  array{type: string, id: int, amount_minor: int, currency: string, seller_user_id: ?int, description: string}  $purchasable
     */
    public function start(User $payer, array $purchasable, string $idempotencyKey): CheckoutResult;
}
