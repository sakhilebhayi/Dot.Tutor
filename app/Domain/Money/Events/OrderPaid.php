<?php

namespace App\Domain\Money\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * An order was paid (or settled with nothing due). Listeners in other domains (marketplace: confirm the
 * booking; learning: open the course) act on it after the money transaction has committed.
 */
class OrderPaid
{
    use Dispatchable;

    public function __construct(public int $orderId) {}
}
