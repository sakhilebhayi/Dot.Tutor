<?php

namespace App\Domain\Money\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A refund was processed on an order line. `fullyRefunded` is true when the line's access was revoked.
 */
class OrderRefunded
{
    use Dispatchable;

    public function __construct(public int $orderId, public int $orderLineId, public int $refundId, public bool $fullyRefunded) {}
}
