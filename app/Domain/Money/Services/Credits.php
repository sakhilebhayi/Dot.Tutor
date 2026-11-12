<?php

namespace App\Domain\Money\Services;

use App\Domain\Money\Contracts\MoneyAudit;
use App\Domain\Money\Exceptions\CheckoutRefused;
use App\Domain\Money\Ledger\Account;
use App\Domain\Money\Ledger\Ledger;
use App\Domain\Money\Ledger\PostingRules;
use App\Domain\Money\Models\CreditGrant;
use App\Domain\Money\Models\Order;
use App\Domain\Money\Money;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Promotional credit: platform funded, ledger backed (learner_credit:{user}). Open orders reserve the
 * credit they will use, so the same rand cannot be spent twice.
 */
class Credits
{
    public function __construct(private Ledger $ledger, private PostingRules $rules, private MoneyAudit $audit) {}

    public function balance(int $userId, ?string $currency = null): Money
    {
        $currency ??= Money::defaultCurrency();

        return Money::of($this->ledger->balance(Account::learnerCredit($userId), $currency), $currency);
    }

    public function reserved(int $userId, ?string $currency = null, ?int $exceptOrderId = null): Money
    {
        $currency ??= Money::defaultCurrency();

        $sum = (int) Order::where('buyer_id', $userId)->where('currency', $currency)->whereIn('status', Order::OPEN)
            ->when($exceptOrderId !== null, fn ($q) => $q->whereKeyNot($exceptOrderId))
            ->sum('credit_minor');

        return Money::of($sum, $currency);
    }

    public function available(int $userId, ?string $currency = null): Money
    {
        $available = $this->balance($userId, $currency)->minus($this->reserved($userId, $currency));

        return $available->isNegative() ? Money::zero($available->currency) : $available;
    }

    /**
     * @throws CheckoutRefused
     */
    public function grant(User $user, int $amountMinor, string $reason, User $actor, string $idempotencyKey): CreditGrant
    {
        if ($amountMinor < 1) {
            throw CheckoutRefused::because('invalid_amount', 'A credit must be at least one cent.');
        }

        return $this->write($user, $amountMinor, $reason, $actor, $idempotencyKey, 'money.credit_granted');
    }

    /**
     * Take back unused credit (never more than the available balance).
     *
     * @throws CheckoutRefused
     */
    public function revoke(User $user, int $amountMinor, string $reason, User $actor, string $idempotencyKey): CreditGrant
    {
        return DB::transaction(function () use ($user, $amountMinor, $reason, $actor, $idempotencyKey) {
            User::whereKey($user->id)->lockForUpdate()->first();
            $available = $this->available($user->id);

            if ($amountMinor < 1 || $amountMinor > $available->minor) {
                throw CheckoutRefused::because('invalid_amount', 'You can take back between '.Money::of(1)->format().' and '.$available->format().'.');
            }

            return $this->write($user, -$amountMinor, $reason, $actor, $idempotencyKey, 'money.credit_revoked');
        });
    }

    private function write(User $user, int $amountMinor, string $reason, User $actor, string $idempotencyKey, string $action): CreditGrant
    {
        $key = hash('sha256', 'credit|'.$actor->id.'|'.$idempotencyKey);
        $existing = CreditGrant::where('idempotency_key', $key)->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            $grant = DB::transaction(function () use ($user, $amountMinor, $reason, $actor, $key) {
                $grant = CreditGrant::create([
                    'user_id' => $user->id,
                    'amount_minor' => $amountMinor,
                    'currency' => Money::defaultCurrency(),
                    'reason' => mb_substr($reason, 0, 255),
                    'idempotency_key' => $key,
                    'granted_by' => $actor->id,
                ]);

                $this->ledger->post($this->rules->creditGranted($grant, $actor->id));

                return $grant;
            });
        } catch (UniqueConstraintViolationException) {
            return CreditGrant::where('idempotency_key', $key)->firstOrFail();
        }

        $this->audit->record($actor, $action, $grant, ['user_id' => $user->id, 'amount_minor' => $amountMinor]);

        return $grant;
    }
}
