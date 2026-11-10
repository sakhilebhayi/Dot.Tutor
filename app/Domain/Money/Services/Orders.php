<?php

namespace App\Domain\Money\Services;

use App\Domain\Money\ApprovalDecision;
use App\Domain\Money\CheckoutStart;
use App\Domain\Money\Contracts\Checkout;
use App\Domain\Money\Contracts\Purchasable;
use App\Domain\Money\Contracts\PurchaseApprovals;
use App\Domain\Money\Exceptions\CheckoutRefused;
use App\Domain\Money\Models\Coupon;
use App\Domain\Money\Models\CouponRedemption;
use App\Domain\Money\Models\Entitlement;
use App\Domain\Money\Models\Order;
use App\Domain\Money\Models\OrderLine;
use App\Domain\Money\Money;
use App\Domain\Money\Purchasables;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Orders: priced server side from each Purchasable, with coupons, promotional credit, commission split and
 * guardian approval, created exactly once per idempotency key. Implements the Checkout seam.
 */
class Orders implements Checkout
{
    public function __construct(
        private Purchasables $purchasables,
        private Coupons $coupons,
        private Credits $credits,
        private CommissionRules $commission,
        private PurchaseApprovals $approvals,
        private Payments $payments,
    ) {}

    /**
     * The full price breakdown a buyer sees before committing (nothing is written).
     *
     * @param  list<array{type: string, id: int}>  $items
     * @return array<string, mixed>
     *
     * @throws CheckoutRefused
     */
    public function quote(User $buyer, array $items, ?string $couponCode = null, bool $useCredit = false): array
    {
        $priced = $this->price($buyer, $items, $couponCode, $useCredit);
        $currency = $priced['currency'];

        return [
            'currency' => $currency,
            'lines' => array_map(fn (array $line) => [
                'type' => $line['type'],
                'item_id' => $line['id'],
                'title' => $line['title'],
                'price' => Money::of($line['price_minor'], $currency),
                'discount' => Money::of($line['tutor_discount_minor'] + $line['platform_discount_minor'], $currency),
                'credit_applied' => Money::of($line['credit_minor'], $currency),
                'to_pay' => Money::of($line['cash_minor'], $currency),
            ], $priced['lines']),
            'subtotal' => Money::of($priced['subtotal_minor'], $currency),
            'discount' => Money::of($priced['discount_minor'], $currency),
            'credit_applied' => Money::of($priced['credit_minor'], $currency),
            'total' => Money::of($priced['total_minor'], $currency),
            'coupon' => $priced['coupon']?->code,
            'credit_available' => $this->credits->available($buyer->id, $currency),
        ];
    }

    public function createOrder(User $buyer, array $items, ?string $idempotencyKey = null, ?string $couponCode = null, bool $useCredit = false): Order
    {
        $key = $idempotencyKey === null || trim($idempotencyKey) === '' ? null : hash('sha256', $buyer->id.'|'.trim($idempotencyKey));

        if ($key !== null && ($existing = Order::where('idempotency_key', $key)->first()) !== null) {
            return $existing;
        }

        try {
            $order = DB::transaction(function () use ($buyer, $items, $key, $couponCode, $useCredit) {
                // One order at a time per buyer: credit reservations and duplicate checks cannot race.
                User::whereKey($buyer->id)->lockForUpdate()->first();

                if ($key !== null && ($existing = Order::where('idempotency_key', $key)->first()) !== null) {
                    return $existing;
                }

                $priced = $this->price($buyer, $items, $couponCode, $useCredit);

                $order = Order::create([
                    'uuid' => (string) Str::uuid(),
                    'buyer_id' => $buyer->id,
                    'status' => Order::PENDING_PAYMENT,
                    'currency' => $priced['currency'],
                    'subtotal_minor' => $priced['subtotal_minor'],
                    'discount_minor' => $priced['discount_minor'],
                    'credit_minor' => $priced['credit_minor'],
                    'total_minor' => $priced['total_minor'],
                    'coupon_id' => $priced['coupon']?->id,
                    'idempotency_key' => $key,
                    'expires_at' => now()->addMinutes(max(5, (int) config('money.orders.ttl_minutes', 60))),
                ]);

                foreach ($priced['lines'] as $line) {
                    OrderLine::create([
                        'order_id' => $order->id,
                        'purchasable_type' => $line['type'],
                        'purchasable_id' => $line['id'],
                        'title' => $line['title'],
                        'seller_id' => $line['seller_id'],
                        'commission_category' => $line['category'],
                        'earning_release' => $line['release'],
                        'price_minor' => $line['price_minor'],
                        'tutor_discount_minor' => $line['tutor_discount_minor'],
                        'platform_discount_minor' => $line['platform_discount_minor'],
                        'credit_minor' => $line['credit_minor'],
                        'cash_minor' => $line['cash_minor'],
                        'commission_bps' => $line['commission_bps'],
                        'commission_rule_id' => $line['commission_rule_id'],
                        'commission_minor' => $line['commission_minor'],
                        'charges_minor' => $line['charges_minor'],
                        'seller_net_minor' => $line['seller_net_minor'],
                    ]);
                }

                if ($priced['coupon'] !== null) {
                    CouponRedemption::create([
                        'coupon_id' => $priced['coupon']->id,
                        'order_id' => $order->id,
                        'user_id' => $buyer->id,
                        'discount_minor' => $priced['discount_minor'],
                        'status' => 'reserved',
                    ]);
                }

                $decision = $this->approvals->assess($buyer, $order);

                if ($decision->outcome === ApprovalDecision::REFUSED) {
                    throw CheckoutRefused::because('guardian_refused', (string) $decision->message, 403);
                }

                $order->forceFill([
                    'status' => $decision->isAllowed() ? Order::PENDING_PAYMENT : Order::AWAITING_GUARDIAN,
                    'guardian_status' => $decision->guardianStatus ?? 'not_needed',
                    'guardian_reference' => $decision->reference,
                    // A guardian may take a while to answer: a held order keeps its items for a week.
                    'expires_at' => $decision->isAllowed() ? $order->expires_at : now()->addDays(7),
                ])->save();

                return $order;
            });
        } catch (UniqueConstraintViolationException $e) {
            if ($key !== null && ($existing = Order::where('idempotency_key', $key)->first()) !== null) {
                return $existing;
            }

            throw $e;
        }

        return $order->fresh(['lines']);
    }

    public function startPayment(Order $order, User $actor, ?string $returnUrl = null): CheckoutStart
    {
        if (! $this->approvals->canPayFor($actor, $order)) {
            throw CheckoutRefused::because('forbidden', 'You cannot pay for this order.', 403);
        }

        $order->refresh();

        if ($order->isSettled()) {
            return new CheckoutStart(CheckoutStart::SETTLED, $order, message: 'This order is already paid.');
        }

        if (! $order->isOpen()) {
            throw CheckoutRefused::because('order_closed', 'This order is closed. Start a new one.', 409);
        }

        if ($order->expires_at !== null && $order->expires_at->isPast()) {
            $this->payments->verifyOpenPayments($order);
            $order->refresh();

            if ($order->isSettled()) {
                return new CheckoutStart(CheckoutStart::SETTLED, $order, message: 'This order is already paid.');
            }

            $this->close($order, Order::EXPIRED);

            throw CheckoutRefused::because('order_expired', 'This order expired before it was paid. Start a new one.', 409);
        }

        $decision = $this->approvals->assess($order->buyer, $order);

        if ($decision->outcome === ApprovalDecision::REFUSED) {
            $order->forceFill(['guardian_status' => 'refused'])->save();
            $this->close($order, Order::GUARDIAN_REFUSED);

            throw CheckoutRefused::because('guardian_refused', (string) $decision->message, 403);
        }

        if (! $decision->isAllowed()) {
            $order->forceFill(['status' => Order::AWAITING_GUARDIAN, 'guardian_status' => 'pending', 'guardian_reference' => $decision->reference ?? $order->guardian_reference])->save();

            return new CheckoutStart(CheckoutStart::HELD, $order, message: $decision->message);
        }

        if ($order->status === Order::AWAITING_GUARDIAN) {
            $order->forceFill(['status' => Order::PENDING_PAYMENT, 'guardian_status' => $decision->guardianStatus ?? 'approved'])->save();
        }

        // Items can change between ordering and paying (a lesson cancelled): check each one again.
        foreach ($order->lines as $line) {
            $purchasable = $this->purchasables->resolve($line->purchasable_type, $line->purchasable_id);
            $refusal = $purchasable === null ? 'An item in this order is no longer available.' : $purchasable->purchaseRefusal($order->buyer);

            if ($refusal !== null) {
                $this->close($order, Order::CANCELLED);

                throw CheckoutRefused::because('item_unavailable', $refusal, 409);
            }
        }

        return $this->payments->start($order, $actor, $returnUrl);
    }

    public function status(Order $order): string
    {
        return (string) $order->fresh()?->status;
    }

    /**
     * @throws CheckoutRefused
     */
    public function cancel(Order $order, User $actor): Order
    {
        if ($actor->id !== $order->buyer_id) {
            throw CheckoutRefused::because('forbidden', 'You cannot cancel this order.', 403);
        }

        $this->payments->verifyOpenPayments($order);
        $order->refresh();

        if (! $order->isOpen()) {
            throw CheckoutRefused::because('order_closed', $order->isSettled() ? 'This order is paid; ask for a refund instead.' : 'This order is already closed.', 409);
        }

        return $this->close($order, Order::CANCELLED);
    }

    /**
     * The guardian's answer, recorded by the safety domain (through the integration agent).
     */
    public function recordGuardianDecision(Order $order, bool $approved, ?string $reference = null): Order
    {
        if (! $order->isOpen()) {
            return $order;
        }

        if (! $approved) {
            $order->forceFill(['guardian_status' => 'refused', 'guardian_reference' => $reference ?? $order->guardian_reference])->save();

            return $this->close($order, Order::GUARDIAN_REFUSED);
        }

        $order->forceFill([
            'guardian_status' => 'approved',
            'guardian_reference' => $reference ?? $order->guardian_reference,
            'status' => Order::PENDING_PAYMENT,
            'expires_at' => now()->addMinutes(max(5, (int) config('money.orders.ttl_minutes', 60))),
        ])->save();

        return $order;
    }

    /**
     * Close an unpaid order: release its coupon use and (implicitly) its credit reservation.
     */
    public function close(Order $order, string $status): Order
    {
        DB::transaction(function () use ($order, $status) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen()) {
                return;
            }

            $locked->forceFill(['status' => $status, 'closed_at' => now()])->save();
            $this->coupons->release($locked->id);
        });

        return $order->refresh();
    }

    /**
     * @param  list<array{type: string, id: int}>  $items
     * @return array{currency: string, lines: list<array<string, mixed>>, subtotal_minor: int, discount_minor: int, credit_minor: int, total_minor: int, coupon: ?Coupon}
     *
     * @throws CheckoutRefused
     */
    private function price(User $buyer, array $items, ?string $couponCode, bool $useCredit): array
    {
        $unique = [];

        foreach ($items as $item) {
            $type = (string) ($item['type'] ?? '');
            $id = (int) ($item['id'] ?? 0);
            $unique[$type.':'.$id] = ['type' => $type, 'id' => $id];
        }

        if ($unique === [] || count($unique) > (int) config('money.orders.max_lines', 20)) {
            throw CheckoutRefused::because('invalid_items', 'An order holds between one and '.config('money.orders.max_lines', 20).' items.');
        }

        $lines = [];
        $currency = null;

        foreach (array_values($unique) as $item) {
            if (! in_array($item['type'], Purchasables::TYPES, true) || ! $this->purchasables->has($item['type'])) {
                throw CheckoutRefused::because('unsupported_item', "Items of type [{$item['type']}] cannot be bought yet.");
            }

            $purchasable = $item['id'] > 0 ? $this->purchasables->resolve($item['type'], $item['id']) : null;

            if ($purchasable === null) {
                throw CheckoutRefused::because('item_not_found', 'An item in this order does not exist.', 404);
            }

            if (($refusal = $purchasable->purchaseRefusal($buyer)) !== null) {
                throw CheckoutRefused::because('item_unavailable', $refusal, 409);
            }

            $this->refuseDuplicate($buyer, $purchasable);

            $price = $purchasable->purchasePrice($buyer);

            if ($price->isNegative()) {
                throw CheckoutRefused::because('item_unavailable', 'An item in this order has no valid price.', 409);
            }

            $currency ??= $price->currency;

            if ($price->currency !== $currency) {
                throw CheckoutRefused::because('mixed_currency', 'Items in different currencies must be bought separately.');
            }

            $lines[] = [
                'type' => $purchasable->purchasableType(),
                'id' => $purchasable->purchasableId(),
                'title' => mb_substr($purchasable->purchaseTitle(), 0, 200),
                'seller_id' => $purchasable->sellerId(),
                'category' => mb_substr($purchasable->commissionCategory(), 0, 60),
                'release' => $purchasable->earningRelease(),
                'price_minor' => $price->minor,
                'tutor_discount_minor' => 0,
                'platform_discount_minor' => 0,
                'credit_minor' => 0,
            ];
        }

        $subtotal = array_sum(array_column($lines, 'price_minor'));
        $coupon = null;

        if ($couponCode !== null && trim($couponCode) !== '') {
            $coupon = $this->coupons->lockForUse($couponCode, $buyer, $currency, $subtotal);
            $field = $coupon->funded_by === Coupon::FUNDED_BY_TUTOR ? 'tutor_discount_minor' : 'platform_discount_minor';

            foreach ($this->coupons->discounts($coupon, $lines) as $index => $discount) {
                $lines[$index][$field] = min($discount, $lines[$index]['price_minor']);
            }
        }

        $remaining = array_map(fn (array $l) => $l['price_minor'] - $l['tutor_discount_minor'] - $l['platform_discount_minor'], $lines);

        if ($useCredit && array_sum($remaining) > 0) {
            $available = $this->credits->available($buyer->id, $currency);
            $use = Money::of(min($available->minor, array_sum($remaining)), $currency);

            if ($use->isPositive()) {
                foreach ($use->allocate($remaining) as $index => $part) {
                    $lines[$index]['credit_minor'] = $part->minor;
                }
            }
        }

        foreach ($lines as $index => $line) {
            $base = Money::of($line['price_minor'] - $line['tutor_discount_minor'], $currency);
            $split = $line['seller_id'] === null
                ? ['commission_bps' => 0, 'commission' => Money::zero($currency), 'charges_total' => Money::zero($currency), 'net' => Money::zero($currency), 'rule_id' => null]
                : $this->commission->split($base, $line['category']);

            $lines[$index] += [
                'cash_minor' => $remaining[$index] - $line['credit_minor'],
                'commission_bps' => $split['commission_bps'],
                'commission_rule_id' => $split['rule_id'],
                'commission_minor' => $split['commission']->minor,
                'charges_minor' => $split['charges_total']->minor,
                'seller_net_minor' => $split['net']->minor,
            ];
        }

        $discount = array_sum(array_map(fn (array $l) => $l['tutor_discount_minor'] + $l['platform_discount_minor'], $lines));
        $credit = array_sum(array_column($lines, 'credit_minor'));

        return [
            'currency' => $currency,
            'lines' => $lines,
            'subtotal_minor' => $subtotal,
            'discount_minor' => $discount,
            'credit_minor' => $credit,
            'total_minor' => $subtotal - $discount - $credit,
            'coupon' => $coupon,
        ];
    }

    /**
     * A one-off item cannot be bought twice: not while it is owned, and not while another open order holds it.
     *
     * @throws CheckoutRefused
     */
    private function refuseDuplicate(User $buyer, Purchasable $purchasable): void
    {
        if ($purchasable->isRepeatable()) {
            return;
        }

        $type = $purchasable->purchasableType();
        $id = $purchasable->purchasableId();

        $owned = Entitlement::where('user_id', $buyer->id)->where('entitled_type', $type)->where('entitled_id', $id)
            ->whereNull('revoked_at')->whereIn('source', ['paid', 'free', 'bundle'])->exists();

        if ($owned) {
            throw CheckoutRefused::because('already_purchased', 'You already have this.', 409);
        }

        $open = OrderLine::query()->join('money_orders', 'money_orders.id', '=', 'money_order_lines.order_id')
            ->where('money_orders.buyer_id', $buyer->id)
            ->whereIn('money_orders.status', Order::OPEN)
            ->where('money_order_lines.purchasable_type', $type)
            ->where('money_order_lines.purchasable_id', $id)
            ->value('money_orders.uuid');

        if ($open !== null) {
            throw CheckoutRefused::because('already_in_open_order', 'This item is already in an unpaid order. Pay or cancel that order first.', 409, ['order_id' => $open]);
        }
    }
}
