<?php

namespace App\Domain\Money;

use InvalidArgumentException;
use JsonSerializable;

/**
 * An amount of money: integer minor units and an ISO 4217 currency. No float ever touches an amount:
 * decimals are parsed as strings, percentages are basis points with half-up rounding, and arithmetic
 * across currencies is refused.
 *
 * format() writes "R 1 250.00" for ZAR (and "USD 1 250.00" otherwise), with a narrow no-break space
 * (U+202F) between the symbol and the figure and between thousands, so an amount never wraps across
 * lines on a small screen.
 */
final readonly class Money implements JsonSerializable
{
    public const NNBSP = "\u{202F}";

    /** Currencies whose minor unit is not 1/100. Everything else is two decimals. */
    private const ZERO_DECIMAL = ['JPY', 'KRW', 'UGX', 'RWF', 'XOF', 'XAF'];

    private const SYMBOLS = ['ZAR' => 'R'];

    public function __construct(public int $minor, public string $currency)
    {
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException('Currency must be an upper-case ISO 4217 code.');
        }
    }

    public static function defaultCurrency(): string
    {
        return strtoupper((string) config('money.currency', 'ZAR'));
    }

    public static function of(int $minor, ?string $currency = null): self
    {
        return new self($minor, strtoupper($currency ?? self::defaultCurrency()));
    }

    public static function zero(?string $currency = null): self
    {
        return self::of(0, $currency);
    }

    /**
     * "125.5", "1 250,00"? No: only a plain decimal with a dot ("1250.00", "-3.5"). Rounded half up at
     * the digit after the minor unit.
     */
    public static function fromDecimal(string $amount, ?string $currency = null): self
    {
        $currency = strtoupper($currency ?? self::defaultCurrency());
        $text = trim($amount);

        if (! preg_match('/^[+-]?\d+(\.\d+)?$/', $text)) {
            throw new InvalidArgumentException("Not a decimal amount: [{$amount}].");
        }

        $negative = str_starts_with($text, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($text, '+-'), 2), 2, '');
        $decimals = self::decimalsFor($currency);
        $fraction = str_pad($fraction, $decimals + 1, '0');
        $minor = ((int) $whole) * (10 ** $decimals) + (int) substr($fraction, 0, $decimals);

        if ((int) $fraction[$decimals] >= 5) {
            $minor++;
        }

        return new self($negative ? -$minor : $minor, $currency);
    }

    public static function decimalsFor(string $currency): int
    {
        return in_array(strtoupper($currency), self::ZERO_DECIMAL, true) ? 0 : 2;
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor + $other->minor, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor - $other->minor, $this->currency);
    }

    public function times(int $quantity): self
    {
        return new self($this->minor * $quantity, $this->currency);
    }

    /** Basis points (1 500 = 15%) of this amount, rounded half up (away from zero) to the minor unit. */
    public function percentBps(int $basisPoints): self
    {
        $product = $this->minor * $basisPoints;

        return new self(intdiv($product + ($product >= 0 ? 5000 : -5000), 10000), $this->currency);
    }

    /** this * numerator / denominator, rounded half up. Used for proportional reversals. */
    public function ratio(int $numerator, int $denominator): self
    {
        if ($denominator <= 0) {
            throw new InvalidArgumentException('Denominator must be positive.');
        }

        $product = $this->minor * $numerator;
        $half = intdiv($denominator, 2);

        return new self(intdiv($product + ($product >= 0 ? $half : -$half), $denominator), $this->currency);
    }

    /**
     * Split into parts proportional to $weights; the rounding remainder goes to the largest weights first,
     * so the parts always add up to exactly this amount.
     *
     * @param  array<int|string, int>  $weights
     * @return array<int|string, self>
     */
    public function allocate(array $weights): array
    {
        $total = array_sum($weights);

        if ($weights === [] || $total <= 0 || min($weights) < 0) {
            throw new InvalidArgumentException('Weights must be non-negative with a positive sum.');
        }

        $parts = [];
        $allocated = 0;

        foreach ($weights as $key => $weight) {
            $parts[$key] = intdiv($this->minor * $weight, $total);
            $allocated += $parts[$key];
        }

        $remainder = $this->minor - $allocated;
        arsort($weights);

        foreach (array_keys($weights) as $key) {
            if ($remainder === 0) {
                break;
            }

            $step = $remainder > 0 ? 1 : -1;
            $parts[$key] += $step;
            $remainder -= $step;
        }

        return array_map(fn (int $minor) => new self($minor, $this->currency), $parts);
    }

    public function min(self $other): self
    {
        $this->assertSameCurrency($other);

        return $this->minor <= $other->minor ? $this : $other;
    }

    public function max(self $other): self
    {
        $this->assertSameCurrency($other);

        return $this->minor >= $other->minor ? $this : $other;
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isPositive(): bool
    {
        return $this->minor > 0;
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->minor === $other->minor;
    }

    /** "1250.00" for CSV and logs. */
    public function toDecimalString(): string
    {
        $decimals = self::decimalsFor($this->currency);
        $sign = $this->minor < 0 ? '-' : '';
        $abs = abs($this->minor);

        if ($decimals === 0) {
            return $sign.$abs;
        }

        $unit = 10 ** $decimals;

        return $sign.intdiv($abs, $unit).'.'.str_pad((string) ($abs % $unit), $decimals, '0', STR_PAD_LEFT);
    }

    /** "R 1 250.00" with narrow no-break spaces; "-R 50.00" for a negative amount. */
    public function format(): string
    {
        $decimal = ltrim($this->toDecimalString(), '-');
        [$whole, $fraction] = array_pad(explode('.', $decimal, 2), 2, null);
        $grouped = implode(self::NNBSP, array_map('strrev', array_reverse(str_split(strrev($whole), 3))));
        $symbol = self::SYMBOLS[$this->currency] ?? $this->currency;

        return ($this->minor < 0 ? '-' : '').$symbol.self::NNBSP.$grouped.($fraction === null ? '' : '.'.$fraction);
    }

    /**
     * @return array{amount_minor: int, currency: string, formatted: string}
     */
    public function jsonSerialize(): array
    {
        return ['amount_minor' => $this->minor, 'currency' => $this->currency, 'formatted' => $this->format()];
    }

    public function __toString(): string
    {
        return $this->format();
    }

    private function assertSameCurrency(self $other): void
    {
        if ($other->currency !== $this->currency) {
            throw new InvalidArgumentException("Cannot combine {$this->currency} with {$other->currency}.");
        }
    }
}
