<?php

namespace App\Domain\Money\Contracts;

use App\Domain\Money\CheckoutStart;
use App\Domain\Money\Exceptions\CheckoutRefused;
use App\Domain\Money\Models\Order;
use App\Models\User;

/**
 * The money domain's front door for marketplace, learning and community (architecture section 3).
 */
interface Checkout
{
    /**
     * Price the items server side and create an order. Repeating the same idempotency key returns the same
     * order (a double click creates one order).
     *
     * @param  list<array{type: string, id: int}>  $items
     *
     * @throws CheckoutRefused
     */
    public function createOrder(User $buyer, array $items, ?string $idempotencyKey = null, ?string $couponCode = null, bool $useCredit = false): Order;

    /**
     * Start (or reopen) payment for an order: a Paystack page to send the payer to, an order settled at once
     * because nothing is due, or an order held for a guardian.
     *
     * @throws CheckoutRefused
     */
    public function startPayment(Order $order, User $actor, ?string $returnUrl = null): CheckoutStart;

    /** The order's current status (one of the Order constants). */
    public function status(Order $order): string;
}
