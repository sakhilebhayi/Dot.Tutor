<?php

namespace App\Domain\Money\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt to collect an order's total. Paid only from Paystack's verify answer with matching
 * reference, amount and currency (or immediately when nothing is due at a card).
 */
class Payment extends Model
{
    public const INITIALISED = 'initialised';

    public const PAID = 'paid';

    public const FAILED = 'failed';

    public const ABANDONED = 'abandoned';

    protected $table = 'money_payments';

    protected $guarded = ['id'];

    protected $hidden = ['authorization_url'];

    protected $casts = [
        'amount_minor' => 'integer',
        'fees_minor' => 'integer',
        'refunded_minor' => 'integer',
        'attempt' => 'integer',
        'authorization_url' => 'encrypted',
        'expires_at' => 'datetime',
        'verified_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
