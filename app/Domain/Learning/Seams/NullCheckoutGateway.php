<?php

namespace App\Domain\Learning\Seams;

use App\Domain\Learning\Contracts\Seams\CheckoutGateway;
use App\Domain\Learning\Contracts\Sellable;
use App\Domain\Learning\Support\CheckoutStart;
use App\Domain\Learning\Support\PriceQuote;
use App\Models\User;

/** Default until the money domain merges: list price only, no coupons, and checkout says plainly it is not on. */
class NullCheckoutGateway implements CheckoutGateway
{
    public function quote(User $buyer, Sellable $item, ?string $couponCode = null): PriceQuote
    {
        return new PriceQuote(
            listMinor: $item->priceMinor(),
            payableMinor: $item->priceMinor(),
            currency: $item->currency(),
            couponCode: $couponCode,
            couponAccepted: false,
            message: $couponCode ? 'Coupons are not available yet.' : null,
        );
    }

    public function start(User $buyer, Sellable $item, ?string $couponCode, string $idempotencyKey): CheckoutStart
    {
        return CheckoutStart::unavailable('Payments are not switched on yet, so paid content cannot be bought.');
    }
}
