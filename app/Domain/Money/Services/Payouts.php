<?php

namespace App\Domain\Money\Services;

use App\Domain\Money\Contracts\MoneyAudit;
use App\Domain\Money\Exceptions\CheckoutRefused;
use App\Domain\Money\Exceptions\PaymentGatewayException;
use App\Domain\Money\Ledger\Ledger;
use App\Domain\Money\Ledger\PostingRules;
use App\Domain\Money\Models\Earning;
use App\Domain\Money\Models\Payout;
use App\Domain\Money\Models\PayoutAccount;
use App\Domain\Money\Models\PayoutBatch;
use App\Domain\Money\Money;
use App\Domain\Money\Paystack\PaystackClient;
use App\Domain\Money\Paystack\PaystackConfig;
use App\Domain\Security\Notifications\PayoutAccountChanged;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Payouts. The manual path is always available: draft a batch (per tutor: available earnings not yet in a
 * payout, net of refund reversals, at or above the minimum, with a bank account), export the bank CSV, pay from
 * the platform's bank, mark each payout paid (or failed, which returns its earnings to the next batch).
 * Paystack Transfers are built but OFF unless money.payouts.paystack_transfers is true; a transfer is paid only
 * when Paystack's transfer verify says success.
 */
class Payouts
{
    public function __construct(
        private Ledger $ledger,
        private PostingRules $rules,
        private PaystackConfig $config,
        private PaystackClient $client,
        private FinanceIssues $issues,
        private MoneyAudit $audit,
    ) {}

    /**
     * @param  array{holder_name: string, account_number: string, bank_name: string, bank_code: string, account_type?: ?string}  $data
     */
    public function saveAccount(User $tutor, array $data): PayoutAccount
    {
        $number = preg_replace('/\D/', '', $data['account_number']);
        $index = PayoutAccount::blindIndex($data['bank_code'], $number);

        $previous = PayoutAccount::where('user_id', $tutor->id)->first();
        $changed = $previous === null || $previous->blind_index !== $index;

        $account = PayoutAccount::updateOrCreate(['user_id' => $tutor->id], [
            'holder_name' => mb_substr(trim($data['holder_name']), 0, 120),
            'account_number' => $number,
            'account_last4' => substr($number, -4),
            'bank_name' => mb_substr(trim($data['bank_name']), 0, 80),
            'bank_code' => mb_substr(trim($data['bank_code']), 0, 20),
            'account_type' => $data['account_type'] ?? 'personal',
            'blind_index' => $index,
            'recipient_code' => null,
        ]);

        // A new destination waits before money goes to it, the tutor hears about it, and a payout being prepared
        // for the old account returns to the next batch (R-47 security S-13: a stolen session redirecting earnings).
        if ($changed && $previous !== null) {
            $account->forceFill(['details_changed_at' => now()])->save();

            // Only payouts whose bank file has not gone out: an exported one may already be paid from the bank.
            Payout::where('payee_id', $tutor->id)->where('status', Payout::DRAFT)
                ->whereIn('batch_id', PayoutBatch::where('status', 'draft')->select('id'))->orderBy('id')->get()
                ->each(fn (Payout $payout) => $this->markFailed($payout, null, 'Bank details changed: paid in a later batch.'));

            $tutor->notify(new PayoutAccountChanged($account->account_last4, (int) config('security.payout_account_hold_hours', 72)));
        }

        if (PayoutAccount::where('blind_index', $index)->where('user_id', '!=', $tutor->id)->exists()) {
            $this->issues->raise('shared_bank_account', "shared-bank:{$index}", 'One bank account is registered by more than one tutor', [
                'severity' => 'critical', 'subject_type' => 'payout_account', 'subject_id' => $account->id,
            ]);
        }

        $this->audit->record($tutor, 'money.payout_account_saved', $account, ['account_last4' => $account->account_last4]);

        return $account;
    }

    public function draftBatch(CarbonInterface $periodEnd, ?User $actor): PayoutBatch
    {
        $currency = Money::defaultCurrency();
        $minimum = max(1, (int) config('money.payouts.minimum_minor', 10000));

        $batch = DB::transaction(function () use ($periodEnd, $actor, $currency, $minimum) {
            $batch = PayoutBatch::create([
                'status' => 'draft',
                'period_end' => $periodEnd,
                'currency' => $currency,
                'created_by' => $actor?->id,
            ]);

            $carried = [];
            $earnings = Earning::where('status', Earning::AVAILABLE)->whereNull('payout_id')->where('currency', $currency)
                ->where('available_at', '<=', $periodEnd)->lockForUpdate()->get()->groupBy('seller_id');

            foreach ($earnings as $sellerId => $rows) {
                $amount = (int) $rows->sum('net_minor');
                $account = PayoutAccount::where('user_id', $sellerId)->first();

                $why = match (true) {
                    $amount <= 0 => 'nothing_owed',
                    $amount < $minimum => 'below_minimum',
                    $account === null => 'no_bank_account',
                    $account->details_changed_at !== null
                        && $account->details_changed_at->isAfter(now()->subHours((int) config('security.payout_account_hold_hours', 72))) => 'bank_account_recently_changed',
                    default => null,
                };

                if ($why !== null) {
                    $carried[] = ['tutor_id' => (int) $sellerId, 'amount_minor' => $amount, 'reason' => $why];

                    continue;
                }

                $payout = Payout::create([
                    'batch_id' => $batch->id,
                    'payee_id' => $sellerId,
                    'payout_account_id' => $account->id,
                    'reference' => 'tutor-payout-'.Str::lower(Str::random(12)),
                    'amount_minor' => $amount,
                    'currency' => $currency,
                    'method' => 'manual',
                    'status' => Payout::DRAFT,
                ]);

                Earning::whereIn('id', $rows->pluck('id'))->update(['payout_id' => $payout->id, 'updated_at' => now()]);
            }

            $payouts = Payout::where('batch_id', $batch->id);
            $batch->forceFill([
                'payout_count' => (clone $payouts)->count(),
                'total_minor' => (int) (clone $payouts)->sum('amount_minor'),
                'carried_forward' => $carried,
            ])->save();

            return $batch;
        });

        $this->audit->record($actor, 'money.payout_batch_drafted', $batch, ['payouts' => $batch->payout_count, 'total_minor' => $batch->total_minor]);

        return $batch;
    }

    /**
     * The bank file: full account numbers, finance only, audited. Cells are formula-neutralised.
     */
    public function exportCsv(PayoutBatch $batch, User $actor): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['reference', 'payee', 'account_holder', 'bank', 'branch_code', 'account_number', 'account_type', 'amount', 'currency'], escape: '');

        foreach ($batch->payouts()->with('account', 'payee')->where('status', Payout::DRAFT)->orderBy('id')->get() as $payout) {
            fputcsv($out, array_map([self::class, 'csvCell'], [
                $payout->reference,
                $payout->payee?->name,
                $payout->account?->holder_name,
                $payout->account?->bank_name,
                $payout->account?->bank_code,
                $payout->account?->account_number,
                $payout->account?->account_type,
                Money::of($payout->amount_minor, $payout->currency)->toDecimalString(),
                $payout->currency,
            ]), escape: '');
        }

        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        $batch->forceFill(['status' => 'exported', 'exported_at' => now()])->save();
        $this->audit->record($actor, 'money.payout_batch_exported', $batch, ['payouts' => $batch->payout_count]);

        return $csv;
    }

    public static function csvCell(mixed $value): string
    {
        $text = (string) $value;

        return $text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! preg_match('/^-?\d+(\.\d+)?$/', $text) ? "'".$text : $text;
    }

    /**
     * @throws CheckoutRefused
     */
    public function markPaid(Payout $payout, ?User $actor, string $method = 'manual'): Payout
    {
        $done = DB::transaction(function () use ($payout, $actor, $method) {
            $locked = Payout::whereKey($payout->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === Payout::PAID) {
                return false;
            }

            if (! in_array($locked->status, [Payout::DRAFT, Payout::PROCESSING], true)) {
                throw CheckoutRefused::because('payout_closed', 'This payout cannot be marked paid.', 409);
            }

            $locked->forceFill(['status' => Payout::PAID, 'method' => $method, 'paid_at' => now(), 'marked_by' => $actor?->id, 'failure_message' => null])->save();
            Earning::where('payout_id', $locked->id)->update(['status' => Earning::PAID, 'updated_at' => now()]);
            $this->ledger->post($this->rules->payoutPaid($locked));

            return true;
        });

        if ($done) {
            $this->audit->record($actor, 'money.payout_paid', $payout, ['amount_minor' => $payout->amount_minor, 'method' => $method]);
        }

        return $payout->refresh();
    }

    /**
     * A payout that did not reach the tutor. Its earnings return to "available" for the next batch; if it had
     * been marked paid, the payment journal is reversed.
     */
    public function markFailed(Payout $payout, ?User $actor, string $reason): Payout
    {
        DB::transaction(function () use ($payout, $reason) {
            $locked = Payout::whereKey($payout->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === Payout::FAILED) {
                return;
            }

            if ($locked->status === Payout::PAID) {
                $this->ledger->post($this->rules->payoutReversed($locked));
            }

            $locked->forceFill(['status' => Payout::FAILED, 'failure_message' => mb_substr($reason, 0, 255)])->save();
            Earning::where('payout_id', $locked->id)->update(['status' => Earning::AVAILABLE, 'payout_id' => null, 'updated_at' => now()]);
        });

        $this->audit->record($actor, 'money.payout_failed', $payout, ['reason' => mb_substr($reason, 0, 120)]);

        return $payout->refresh();
    }

    public function transfersEnabled(): bool
    {
        return (bool) config('money.payouts.paystack_transfers') && $this->config->canTakePayments();
    }

    /**
     * Send one draft payout with Paystack Transfers (only when switched on).
     *
     * @throws CheckoutRefused
     */
    public function sendTransfer(Payout $payout, User $actor): Payout
    {
        if (! $this->transfersEnabled()) {
            throw CheckoutRefused::because('transfers_off', 'Paystack Transfers are switched off. Pay from the bank file and mark the payout paid.', 409);
        }

        if ($payout->status !== Payout::DRAFT) {
            throw CheckoutRefused::because('payout_closed', 'Only a draft payout can be sent.', 409);
        }

        $account = $payout->account;

        if ($account === null) {
            throw CheckoutRefused::because('no_bank_account', 'This tutor has no bank account.', 409);
        }

        try {
            if ($account->recipient_code === null) {
                $account->forceFill(['recipient_code' => $this->client->createTransferRecipient($account->holder_name, $account->account_number, $account->bank_code, $payout->currency)])->save();
            }

            $transfer = $this->client->initiateTransfer($payout->amount_minor, $account->recipient_code, $payout->reference, 'Dot.Tutor earnings', $payout->currency);
        } catch (PaymentGatewayException $e) {
            throw CheckoutRefused::because('transfer_failed', $e->getMessage(), 502);
        }

        $payout->forceFill(['status' => Payout::PROCESSING, 'method' => 'paystack_transfer', 'transfer_code' => $transfer->transferCode])->save();
        $this->audit->record($actor, 'money.payout_transfer_sent', $payout, ['amount_minor' => $payout->amount_minor]);

        return $transfer->status === 'success' ? $this->syncTransfer($payout) : $payout;
    }

    /** Apply Paystack's transfer verify answer (after a transfer.* webhook or from the sweep). */
    public function syncTransfer(Payout $payout): Payout
    {
        if ($payout->status !== Payout::PROCESSING) {
            return $payout;
        }

        try {
            $transfer = $this->client->verifyTransfer($payout->reference);
        } catch (PaymentGatewayException) {
            return $payout;
        }

        if ($transfer->amountMinor !== $payout->amount_minor) {
            $this->issues->raise('transfer_mismatch', "transfer-amount:{$payout->id}", "Payout {$payout->reference}: Paystack reports a different amount", [
                'severity' => 'critical', 'subject_type' => 'payout', 'subject_id' => $payout->id, 'amount_minor' => $transfer->amountMinor, 'currency' => $payout->currency,
            ]);

            return $payout;
        }

        return match ($transfer->status) {
            'success' => $this->markPaid($payout, null, 'paystack_transfer'),
            'failed', 'reversed', 'abandoned' => $this->markFailed($payout, null, 'Paystack transfer '.$transfer->status.($transfer->reason ? ': '.$transfer->reason : '')),
            default => $payout,
        };
    }

    /**
     * A tutor's payout statement: the payout and every earning in it.
     *
     * @return array<string, mixed>
     */
    public function statement(Payout $payout): array
    {
        return [
            'reference' => $payout->reference,
            'status' => $payout->status,
            'amount' => Money::of($payout->amount_minor, $payout->currency),
            'method' => $payout->method,
            'paid_at' => $payout->paid_at?->toIso8601String(),
            'account_ending' => $payout->account?->account_last4,
            'earnings' => $payout->earnings()->with('orderLine')->orderBy('id')->get()->map(fn (Earning $e) => Earnings::present($e))->all(),
        ];
    }
}
