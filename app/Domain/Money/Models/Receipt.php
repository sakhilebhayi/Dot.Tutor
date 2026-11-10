<?php

namespace App\Domain\Money\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A frozen receipt: gapless number and a snapshot of what was bought, from whom, for how much.
 */
class Receipt extends Model
{
    protected $table = 'money_receipts';

    protected $guarded = ['id'];

    protected $casts = [
        'snapshot' => 'array',
        'issued_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Receipts are immutable.'));
        static::deleting(fn () => throw new LogicException('Receipts are immutable.'));
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
