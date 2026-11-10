<?php

namespace App\Domain\Money\Services;

use App\Domain\Money\Contracts\MoneyAudit;
use App\Domain\Money\Exceptions\CheckoutRefused;
use App\Domain\Money\Models\Coupon;
use App\Domain\Money\Models\CouponRedemption;
use App\Domain\Money\Money;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Discount codes: tutor-owned (funded by the tutor, valid on that tutor's items only) or platform-funded.
 * Limits: start and expiry, total redemptions, per-person uses, minimum order, item type. A use is reserved
 * when the order is created (so limits hold under concurrency: the coupon row is locked), confirmed when it is
 * paid and released when the order closes unpaid.
 */
class Coupons
{
    public function __construct(private MoneyAudit $audit) {}

    /**
     * @param  array{code: string, kind: string, percent?: ?float, amount_minor?: ?int, applies_to_type?: ?string, min_order_minor?: ?int, max_redemptions?: ?int, per_user_limit?: ?int, starts_at?: ?string, expires_at?: ?string}  $data
     *
     * @throws CheckoutRefused
     */
    public function create(User $actor, string $fundedBy, array $data): Coupon
    {
        $code = Coupon::normalise($data['code']);

        if (strlen($code) < 4) {
            throw CheckoutRefused::because('invalid_code', 'A code has at least four letters or digits.');
        }

        if (Coupon::where('code', $code)->exists()) {
            throw CheckoutRefused::because('code_taken', 'That code is already in use. Choose another.', 409);
        }

        $percentBps = $data['kind'] === 'percent' ? CommissionRules::percentToBps((float) ($data['percent'] ?? 0)) : null;

        if ($data['kind'] === 'percent' && ($percentBps < 1 || $percentBps > 10000)) {
            throw CheckoutRefused::because('invalid_percent', 'A percentage discount is between 0.01 and 100.');
        }

        if ($data['kind'] === 'fixed' && (int) ($data['amount_minor'] ?? 0) < 1) {
            throw CheckoutRefused::because('invalid_amount', 'A fixed discount must be more than zero.');
        }

        $coupon = Coupon::create([
            'code' => $code,
            'funded_by' => $fundedBy,
            'owner_id' => $fundedBy === Coupon::FUNDED_BY_TUTOR ? $actor->id : null,
            'kind' => $data['kind'],
            'percent_bps' => $percentBps,
            'amount_minor' => $data['kind'] === 'fixed' ? (int) $data['amount_minor'] : null,
            'currency' => Money::defaultCurrency(),
            'applies_to_type' => $data['applies_to_type'] ?? null,
            'min_order_minor' => (int) ($data['min_order_minor'] ?? 0),
            'max_redemptions' => $data['max_redemptions'] ?? null,
            'per_user_limit' => max(1, (int) ($data['per_user_limit'] ?? 1)),
            'starts_at' => isset($data['starts_at']) ? CarbonImmutable::parse($data['starts_at']) : null,
            'expires_at' => isset($data['expires_at']) ? CarbonImmutable::parse($data['expires_at']) : null,
            'is_active' => true,
            'created_by' => $actor->id,
        ]);

        $this->audit->record($actor, 'money.coupon_created', $coupon, ['code' => $code, 'funded_by' => $fundedBy]);

        return $coupon;
    }

    /**
     * Find the coupon and check every limit for this buyer. The caller holds a transaction; the coupon row is
     * locked so redemption counts cannot race.
     *
     * @throws CheckoutRefused
     */
    public function lockForUse(string $code, User $buyer, string $currency, int $subtotalMinor): Coupon
    {
        $coupon = Coupon::where('code', Coupon::normalise($code))->lockForUpdate()->first();
        $now = now();

        $refusal = match (true) {
            $coupon === null || ! $coupon->is_active => 'That code is not valid.',
            $coupon->starts_at !== null && $coupon->starts_at->isAfter($now) => 'That code is not valid yet.',
            $coupon->expires_at !== null && $coupon->expires_at->isBefore($now) => 'That code has expired.',
            $coupon->currency !== $currency => 'That code is not valid for this currency.',
            $subtotalMinor < $coupon->min_order_minor => 'That code needs an order of at least '.Money::of($coupon->min_order_minor, $currency)->format().'.',
            $coupon->owner_id !== null && $coupon->owner_id === $buyer->id => 'You cannot use your own code.',
            default => null,
        };

        if ($refusal !== null) {
            throw CheckoutRefused::because('coupon_invalid', $refusal);
        }

        $used = CouponRedemption::where('coupon_id', $coupon->id)->whereIn('status', ['reserved', 'confirmed']);

        if ($coupon->max_redemptions !== null && (clone $used)->count() >= $coupon->max_redemptions) {
            throw CheckoutRefused::because('coupon_exhausted', 'That code has been used up.');
        }

        if ((clone $used)->where('user_id', $buyer->id)->count() >= $coupon->per_user_limit) {
            throw CheckoutRefused::because('coupon_used', 'You have already used that code.');
        }

        return $coupon;
    }

    /**
     * Discount per line index for the lines this coupon applies to.
     *
     * @param  array<int, array{type: string, seller_id: ?int, price_minor: int}>  $lines
     * @return array<int, int>
     *
     * @throws CheckoutRefused
     */
    public function discounts(Coupon $coupon, array $lines): array
    {
        $eligible = array_filter($lines, fn (array $line) => ($coupon->applies_to_type === null || $coupon->applies_to_type === $line['type'])
            && ($coupon->funded_by === Coupon::FUNDED_BY_PLATFORM || $line['seller_id'] === $coupon->owner_id)
            && $line['price_minor'] > 0);

        if ($eligible === []) {
            throw CheckoutRefused::because('coupon_not_applicable', 'That code does not apply to anything in this order.');
        }

        if ($coupon->kind === 'percent') {
            return array_map(fn (array $line) => Money::of($line['price_minor'], $coupon->currency)->percentBps($coupon->percent_bps)->minor, $eligible);
        }

        $eligibleTotal = array_sum(array_column($eligible, 'price_minor'));
        $discount = Money::of(min((int) $coupon->amount_minor, $eligibleTotal), $coupon->currency);

        return array_map(fn (Money $part) => $part->minor, $discount->allocate(array_map(fn (array $line) => $line['price_minor'], $eligible)));
    }

    public function release(int $orderId): void
    {
        CouponRedemption::where('order_id', $orderId)->where('status', 'reserved')->update(['status' => 'released', 'updated_at' => now()]);
    }

    public function confirm(int $orderId): void
    {
        CouponRedemption::where('order_id', $orderId)->where('status', 'reserved')->update(['status' => 'confirmed', 'updated_at' => now()]);
    }
}
