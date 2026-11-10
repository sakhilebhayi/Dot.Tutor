<?php

namespace App\Domain\Learning\Contracts\Seams;

use App\Domain\Learning\Contracts\Sellable;
use App\Domain\Learning\Support\CheckoutStart;
use App\Domain\Learning\Support\PriceQuote;
use App\Models\User;

/**
 * The learning side of Money\Contracts\Checkout. Prices, coupons and promotions are applied by the money domain;
 * the learning domain only shows the quote and hands the buyer to checkout.
 */
interface CheckoutGateway
{
    public function quote(User $buyer, Sellable $item, ?string $couponCode = null): PriceQuote;

    public function start(User $buyer, Sellable $item, ?string $couponCode, string $idempotencyKey): CheckoutStart;
}
