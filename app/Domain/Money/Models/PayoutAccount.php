<?php

namespace App\Domain\Money\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tutor's bank account for payouts. Holder and number are encrypted at rest; only the last four digits
 * are ever shown. The blind index (HMAC) lets operations see one account used by two payees.
 */
class PayoutAccount extends Model
{
    protected $table = 'money_payout_accounts';

    protected $guarded = ['id'];

    protected $hidden = ['holder_name', 'account_number', 'blind_index', 'recipient_code'];

    protected $casts = [
        'holder_name' => 'encrypted',
        'account_number' => 'encrypted',
    ];

    public static function blindIndex(string $bankCode, string $accountNumber): string
    {
        return hash_hmac('sha256', $bankCode.'|'.preg_replace('/\D/', '', $accountNumber), (string) config('app.key'));
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toSafeArray(): array
    {
        return [
            'bank_name' => $this->bank_name,
            'bank_code' => $this->bank_code,
            'account_type' => $this->account_type,
            'account_ending' => $this->account_last4,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
