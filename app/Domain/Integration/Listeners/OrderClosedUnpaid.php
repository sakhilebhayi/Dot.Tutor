<?php

namespace App\Domain\Integration\Listeners;

use App\Domain\Integration\Support\MoneyTypes;
use App\Domain\Marketplace\Services\PaymentService;
use App\Domain\Money\Models\Order;

/**
 * An order closed without payment (expired, cancelled, or a guardian refused): the marketplace item is marked
 * payment "failed" so the learner sees it still needs paying (the booking's own hold decides when it is released).
 */
class OrderClosedUnpaid
{
    public function __construct(private readonly PaymentService $payments) {}

    public function handle(Order $order): void
    {
        if (! $order->wasChanged('status') || ! in_array($order->status, [Order::EXPIRED, Order::CANCELLED, Order::GUARDIAN_REFUSED], true)) {
            return;
        }

        foreach ($order->lines()->get() as $line) {
            $type = $line->purchasable_type === 'booking' ? 'tutor_session' : MoneyTypes::marketplaceType($line->purchasable_type);

            if ($type !== null) {
                $this->payments->failed($type, (int) $line->purchasable_id, $order->uuid);
            }
        }
    }
}
