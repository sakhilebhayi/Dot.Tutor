<?php

namespace App\Domain\Money\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A card dispute (chargeback) record: from a Paystack charge.dispute.* webhook or entered by finance.
 */
class Dispute extends Model
{
    public const STATUSES = ['open', 'won', 'lost', 'resolved'];

    protected $table = 'money_disputes';

    protected $guarded = ['id'];

    protected $casts = [
        'amount_minor' => 'integer',
        'resolved_at' => 'datetime',
    ];

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
