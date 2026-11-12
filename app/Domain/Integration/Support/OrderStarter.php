<?php

namespace App\Domain\Integration\Support;

use App\Domain\Money\CheckoutStart;
use App\Domain\Money\Contracts\Checkout;
use App\Domain\Money\Models\Order;
use App\Domain\Money\Models\OrderLine;
use App\Domain\Money\Paystack\PaystackConfig;
use App\Models\User;

/**
 * Turns "pay for this item" from another domain into a money order and a payment start. An open order that already
 * holds the item is reused (a learner coming back after a guardian approved, or pressing pay twice); otherwise a new
 * order is created under an idempotency key derived from the caller's key and how many closed orders came before
 * (so a new attempt after an expired order is a new order, while a double click is not).
 */
class OrderStarter
{
    public function __construct(private readonly Checkout $checkout, private readonly PaystackConfig $paystack) {}

    /**
     * Why online payment cannot start on this environment, or null when it can. Callers check this before creating
     * an order, so a learner on an environment without Paystack is told so and no unpayable order is left open.
     * (A 100% coupon on such an environment is therefore not redeemable through these hand-offs; noted in docs.)
     */
    public function unavailableReason(): ?string
    {
        return $this->paystack->canTakePayments() ? null : ($this->paystack->notice() ?? 'Online payment is not available on this environment.');
    }

    public function start(User $payer, string $moneyType, int $id, string $idempotencyKey, ?string $couponCode = null, ?string $returnUrl = null): CheckoutStart
    {
        $order = $this->openOrderFor($payer, $moneyType, $id);

        if ($order === null) {
            $previous = OrderLine::query()->join('money_orders', 'money_orders.id', '=', 'money_order_lines.order_id')
                ->where('money_orders.buyer_id', $payer->id)
                ->where('money_order_lines.purchasable_type', $moneyType)
                ->where('money_order_lines.purchasable_id', $id)
                ->count();

            $order = $this->checkout->createOrder($payer, [['type' => $moneyType, 'id' => $id]], 'integ|'.$moneyType.'|'.$id.'|'.$previous.'|'.$idempotencyKey, $couponCode);
        }

        return $this->checkout->startPayment($order, $payer, $returnUrl);
    }

    public function openOrderFor(User $buyer, string $moneyType, int $id): ?Order
    {
        $orderId = OrderLine::query()->join('money_orders', 'money_orders.id', '=', 'money_order_lines.order_id')
            ->where('money_orders.buyer_id', $buyer->id)
            ->whereIn('money_orders.status', Order::OPEN)
            ->where('money_order_lines.purchasable_type', $moneyType)
            ->where('money_order_lines.purchasable_id', $id)
            ->orderByDesc('money_orders.id')
            ->value('money_orders.id');

        return $orderId === null ? null : Order::query()->find($orderId);
    }
}
