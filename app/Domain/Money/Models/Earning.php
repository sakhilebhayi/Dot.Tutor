<?php

namespace App\Domain\Money\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a tutor earns from one sale (or gives back on a refund). pending -> available -> paid.
 */
class Earning extends Model
{
    public const KIND_SALE = 'sale';

    public const KIND_REVERSAL = 'reversal';

    public const PENDING = 'pending';

    public const AVAILABLE = 'available';

    public const PAID = 'paid';

    public const CANCELLED = 'cancelled';

    protected $table = 'money_earnings';

    protected $guarded = ['id'];

    protected $casts = [
        'gross_minor' => 'integer',
        'commission_minor' => 'integer',
        'charges_minor' => 'integer',
        'net_minor' => 'integer',
        'release_after' => 'datetime',
        'available_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    /** @return BelongsTo<OrderLine, $this> */
    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class);
    }

    /** @return BelongsTo<Payout, $this> */
    public function payout(): BelongsTo
    {
        return $this->belongsTo(Payout::class);
    }
}
