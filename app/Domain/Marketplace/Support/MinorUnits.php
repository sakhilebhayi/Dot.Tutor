<?php

namespace App\Domain\Marketplace\Support;

use App\Domain\Money\Money;

/**
 * Decimal <-> integer minor units until the money agent's Money value object
 * lands. Exponent per ISO 4217; the currencies this platform names are listed,
 * everything else is treated as two decimals.
 */
final class MinorUnits
{
    /** @var array<string, int> */
    private const EXPONENTS = ['JPY' => 0, 'KRW' => 0, 'UGX' => 0, 'RWF' => 0, 'XOF' => 0, 'XAF' => 0, 'BWP' => 2, 'ZAR' => 2, 'NAD' => 2, 'USD' => 2, 'EUR' => 2, 'GBP' => 2, 'KES' => 2, 'NGN' => 2, 'MZN' => 2];

    public static function exponent(?string $currency): int
    {
        return self::EXPONENTS[strtoupper((string) $currency)] ?? 2;
    }

    public static function fromDecimal(float|int|string|null $amount, ?string $currency): int
    {
        return (int) round(((float) $amount) * (10 ** self::exponent($currency)));
    }

    public static function toDecimal(int $minor, ?string $currency): string
    {
        $exp = self::exponent($currency);

        return number_format($minor / (10 ** $exp), $exp, '.', '');
    }

    /** "R350.00": the one money display format (App\Domain\Money\Money::format(), D-2104). */
    public static function format(?int $minor, ?string $currency): string
    {
        $currency = strtoupper($currency ?: (string) config('tutor.currency'));

        return $minor === null ? '' : Money::of($minor, $currency)->format();
    }
}
