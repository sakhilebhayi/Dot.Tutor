<?php

namespace App\Support;

class Money
{
    /** "ZAR 350.00" -- code first, no symbol guessing. */
    public static function format(float|int|string|null $amount, ?string $currency = null, int $decimals = 2): string
    {
        return trim(($currency ?: config('tutor.currency')).' '.number_format((float) $amount, $decimals));
    }
}
