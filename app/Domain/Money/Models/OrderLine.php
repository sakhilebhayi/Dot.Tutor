<?php

namespace App\Domain\Money\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One purchasable in an order with its frozen economics: price, who funded which discount, credit used,
 * cash paid, the commission rule applied, other charges and the tutor's net.
 */
class OrderLine extends Model
{
    protected $table = 'money_order_lines';

    protected $guarded = ['id'];

    protected $casts = [
        'price_minor' => 'integer',
        'tutor_discount_minor' => 'integer',
        'platform_discount_minor' => 'integer',
        'credit_minor' => 'integer',
        'cash_minor' => 'integer',
        'commission_bps' => 'integer',
        'commission_minor' => 'integer',
        'charges_minor' => 'integer',
        'seller_net_minor' => 'integer',
        'refunded_minor' => 'integer',
    ];

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<User, $this> */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    /** @return HasOne<Earning, $this> */
    public function earning(): HasOne
    {
        return $this->hasOne(Earning::class)->where('kind', Earning::KIND_SALE);
    }

    /** The tutor's selling price after their own discount: the base commission is taken from. */
    public function commissionBaseMinor(): int
    {
        return $this->price_minor - $this->tutor_discount_minor;
    }

    public function refundableMinor(): int
    {
        return max(0, $this->cash_minor - $this->refunded_minor);
    }
}
