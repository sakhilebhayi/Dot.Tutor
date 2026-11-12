<?php

namespace App\Domain\Money\Models;

use App\Domain\Money\Money;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * What a buyer is buying: lines priced server side, discounts, credit and the amount charged to a card.
 */
class Order extends Model
{
    public const PENDING_PAYMENT = 'pending_payment';

    public const AWAITING_GUARDIAN = 'awaiting_guardian';

    public const PAID = 'paid';

    public const PARTIALLY_REFUNDED = 'partially_refunded';

    public const REFUNDED = 'refunded';

    public const CANCELLED = 'cancelled';

    public const EXPIRED = 'expired';

    public const GUARDIAN_REFUSED = 'guardian_refused';

    /** States that hold items, coupon uses and credit. */
    public const OPEN = [self::PENDING_PAYMENT, self::AWAITING_GUARDIAN];

    /** States in which the buyer has paid (fully or partly refunded later). */
    public const SETTLED = [self::PAID, self::PARTIALLY_REFUNDED, self::REFUNDED];

    protected $table = 'money_orders';

    protected $guarded = ['id'];

    protected $casts = [
        'subtotal_minor' => 'integer',
        'discount_minor' => 'integer',
        'credit_minor' => 'integer',
        'total_minor' => 'integer',
        'refunded_minor' => 'integer',
        'expires_at' => 'datetime',
        'paid_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return BelongsTo<User, $this> */
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    /** @return HasMany<OrderLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return BelongsTo<Coupon, $this> */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /** @return HasOne<Receipt, $this> */
    public function receipt(): HasOne
    {
        return $this->hasOne(Receipt::class);
    }

    /** @return HasMany<Refund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function isSettled(): bool
    {
        return in_array($this->status, self::SETTLED, true);
    }

    public function total(): Money
    {
        return Money::of($this->total_minor, $this->currency);
    }

    /**
     * @return array<string, mixed>
     */
    public function toApi(): array
    {
        $this->loadMissing('lines', 'receipt', 'coupon');

        return [
            'id' => $this->uuid,
            'status' => $this->status,
            'guardian_status' => $this->guardian_status,
            'currency' => $this->currency,
            'subtotal' => Money::of($this->subtotal_minor, $this->currency),
            'discount' => Money::of($this->discount_minor, $this->currency),
            'credit_applied' => Money::of($this->credit_minor, $this->currency),
            'total' => $this->total(),
            'refunded' => Money::of($this->refunded_minor, $this->currency),
            'coupon' => $this->coupon?->code,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'receipt_number' => $this->receipt?->number,
            'lines' => $this->lines->map(fn (OrderLine $line) => [
                'id' => $line->id,
                'type' => $line->purchasable_type,
                'item_id' => $line->purchasable_id,
                'title' => $line->title,
                'price' => Money::of($line->price_minor, $this->currency),
                'discount' => Money::of($line->tutor_discount_minor + $line->platform_discount_minor, $this->currency),
                'credit_applied' => Money::of($line->credit_minor, $this->currency),
                'to_pay' => Money::of($line->cash_minor, $this->currency),
            ])->values()->all(),
        ];
    }
}
