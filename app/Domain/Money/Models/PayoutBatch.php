<?php

namespace App\Domain\Money\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayoutBatch extends Model
{
    protected $table = 'money_payout_batches';

    protected $guarded = ['id'];

    protected $casts = [
        'period_end' => 'datetime',
        'exported_at' => 'datetime',
        'carried_forward' => 'array',
        'payout_count' => 'integer',
        'total_minor' => 'integer',
    ];

    /** @return HasMany<Payout, $this> */
    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class, 'batch_id');
    }
}
