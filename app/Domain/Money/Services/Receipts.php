<?php

namespace App\Domain\Money\Services;

use App\Domain\Money\Models\Order;
use App\Domain\Money\Models\Payment;
use App\Domain\Money\Models\Receipt;
use App\Domain\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * Issues one receipt per paid order: a gapless number (row-locked sequence) and a frozen snapshot. The
 * operator's identity comes from config/money.php and a missing value is left off, never invented. A VAT line
 * appears only when the operator is registered AND a VAT number is configured; prices are VAT-inclusive.
 */
class Receipts
{
    public function issue(Order $order, ?Payment $payment): Receipt
    {
        $existing = Receipt::where('order_id', $order->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($order, $payment) {
            $number = $this->nextNumber();
            $order->loadMissing('lines', 'buyer', 'coupon');
            $currency = $order->currency;

            $snapshot = [
                'operator' => self::operatorIdentity(),
                'buyer' => ['name' => $order->buyer?->name],
                'order' => $order->uuid,
                'currency' => $currency,
                'lines' => $order->lines->map(fn ($line) => [
                    'title' => $line->title,
                    'type' => $line->purchasable_type,
                    'seller' => $line->seller?->name,
                    'price_minor' => $line->price_minor,
                    'discount_minor' => $line->tutor_discount_minor + $line->platform_discount_minor,
                    'credit_minor' => $line->credit_minor,
                    'paid_minor' => $line->cash_minor,
                ])->values()->all(),
                'subtotal_minor' => $order->subtotal_minor,
                'discount_minor' => $order->discount_minor,
                'coupon' => $order->coupon?->code,
                'credit_minor' => $order->credit_minor,
                'total_minor' => $order->total_minor,
                'payment' => $payment === null ? ['method' => 'Nothing to pay by card'] : [
                    'method' => 'Paystack'.($payment->channel ? ' ('.$payment->channel.')' : ''),
                    'reference' => $payment->reference,
                ],
                'vat' => self::vatLine($order->total_minor, $currency),
                'paid_at' => ($order->paid_at ?? now())->toIso8601String(),
            ];

            return Receipt::create([
                'number' => $number,
                'order_id' => $order->id,
                'buyer_id' => $order->buyer_id,
                'snapshot' => $snapshot,
                'issued_at' => now(),
            ]);
        });
    }

    /**
     * @return array<string, string>
     */
    public static function operatorIdentity(): array
    {
        return array_filter([
            'legal_name' => config('money.operator.legal_name'),
            'trading_name' => config('money.operator.trading_name'),
            'registration_number' => config('money.operator.registration_number'),
            'address' => config('money.operator.address'),
            'support_email' => config('money.operator.support_email'),
            'vat_number' => self::vatConfigured() ? config('money.vat.number') : null,
        ], fn ($value) => is_string($value) && trim($value) !== '');
    }

    public static function vatConfigured(): bool
    {
        return (bool) config('money.vat.registered') && trim((string) config('money.vat.number')) !== '';
    }

    /**
     * @return array{rate_percent: float, included_minor: int}|null
     */
    public static function vatLine(int $totalMinor, string $currency): ?array
    {
        if (! self::vatConfigured() || $totalMinor <= 0) {
            return null;
        }

        $rateBps = CommissionRules::percentToBps((float) config('money.vat.rate_percent', 15));

        return [
            'rate_percent' => $rateBps / 100,
            'included_minor' => Money::of($totalMinor, $currency)->ratio($rateBps, 10000 + $rateBps)->minor,
        ];
    }

    private function nextNumber(): string
    {
        $name = 'receipt';
        DB::table('money_sequences')->insertOrIgnore(['name' => $name, 'last_value' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $current = (int) DB::table('money_sequences')->where('name', $name)->lockForUpdate()->value('last_value');
        $next = $current + 1;
        DB::table('money_sequences')->where('name', $name)->update(['last_value' => $next, 'updated_at' => now()]);

        return config('money.receipts.prefix', 'TUT').'-'.str_pad((string) $next, 8, '0', STR_PAD_LEFT);
    }
}
