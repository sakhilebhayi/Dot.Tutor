<?php

namespace App\Support;

use App\Domain\Marketplace\Support\MinorUnits;
use App\Domain\Money\Money as DomainMoney;

class Money
{
    /** A decimal amount for display, in the one money format (App\Domain\Money\Money::format(): "R350.00"). */
    public static function format(float|int|string|null $amount, ?string $currency = null, int $decimals = 2): string
    {
        $currency = strtoupper($currency ?: (string) config('tutor.currency'));

        return DomainMoney::of(MinorUnits::fromDecimal($amount, $currency), $currency)->format();
    }
}
