<?php

namespace App\Domain\Money\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payout extends Model
{
    public const DRAFT = 'draft';

    public const PROCESSING = 'processing';

    public const PAID = 'paid';

    public const FAILED = 'failed';

    protected $table = 'money_payouts';

    protected $guarded = ['id'];

    protected $casts = [
        'amount_minor' => 'integer',
        'paid_at' => 'datetime',
    ];

    /** @return BelongsTo<PayoutBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(PayoutBatch::class, 'batch_id');
    }

    /** @return BelongsTo<User, $this> */
    public function payee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payee_id');
    }

    /** @return BelongsTo<PayoutAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(PayoutAccount::class, 'payout_account_id');
    }

    /** @return HasMany<Earning, $this> */
    public function earnings(): HasMany
    {
        return $this->hasMany(Earning::class);
    }
}
