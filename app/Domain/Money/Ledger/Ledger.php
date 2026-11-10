<?php

namespace App\Domain\Money\Ledger;

use App\Domain\Money\Ledger\Models\LedgerEntry;
use App\Domain\Money\Ledger\Models\LedgerJournal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of the ledger. post() refuses an unbalanced or empty journal, and posting the same
 * idempotency key twice returns the first journal without writing anything, so a duplicate webhook, a
 * repeated event or a retried job can never count money twice.
 */
class Ledger
{
    /**
     * @throws UnbalancedJournal
     */
    public function post(JournalEntry $entry): LedgerJournal
    {
        if (! $entry->isBalanced()) {
            throw UnbalancedJournal::for($entry);
        }

        $existing = LedgerJournal::where('idempotency_key', $entry->idempotencyKey)->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            // Savepoint: a lost race on the unique key rolls back only this insert.
            return DB::transaction(function () use ($entry) {
                $now = now();

                $journal = LedgerJournal::create([
                    'kind' => $entry->kind,
                    'idempotency_key' => $entry->idempotencyKey,
                    'currency' => $entry->currency,
                    'order_id' => $entry->refs['order_id'] ?? null,
                    'payment_id' => $entry->refs['payment_id'] ?? null,
                    'refund_id' => $entry->refs['refund_id'] ?? null,
                    'payout_id' => $entry->refs['payout_id'] ?? null,
                    'earning_id' => $entry->refs['earning_id'] ?? null,
                    'reason' => mb_substr($entry->reason, 0, 500),
                    'posted_by' => $entry->postedBy,
                    'posted_at' => $now,
                ]);

                foreach ($entry->lines() as $line) {
                    [$type, $party] = Account::parse($line['account']);

                    LedgerEntry::create([
                        'journal_id' => $journal->id,
                        'account' => $line['account'],
                        'account_type' => $type,
                        'party_id' => $party,
                        'debit_minor' => $line['debit'],
                        'credit_minor' => $line['credit'],
                        'currency' => $entry->currency,
                        'created_at' => $now,
                    ]);
                }

                return $journal;
            });
        } catch (UniqueConstraintViolationException) {
            return LedgerJournal::where('idempotency_key', $entry->idempotencyKey)->firstOrFail();
        }
    }

    public function hasPosted(string $idempotencyKey): bool
    {
        return LedgerJournal::where('idempotency_key', $idempotencyKey)->exists();
    }

    /** Debit minus credit for one account (positive = debit balance). */
    public function rawBalance(string $account, string $currency): int
    {
        $row = LedgerEntry::where('account', $account)->where('currency', $currency)
            ->selectRaw('COALESCE(SUM(debit_minor), 0) as d, COALESCE(SUM(credit_minor), 0) as c')
            ->first();

        return (int) $row->d - (int) $row->c;
    }

    /** Balance on the account's normal side (assets/expenses: debit minus credit; others: credit minus debit). */
    public function balance(string $account, string $currency): int
    {
        [$type] = Account::parse($account);
        $raw = $this->rawBalance($account, $currency);

        return Account::isDebitNormal($type) ? $raw : -$raw;
    }

    /**
     * Every account's normal-side balance for a currency.
     *
     * @return Collection<int, array{account: string, label: string, nature: string, debit_minor: int, credit_minor: int, balance_minor: int}>
     */
    public function trialBalance(string $currency): Collection
    {
        return LedgerEntry::query()->where('currency', $currency)
            ->select('account')
            ->selectRaw('SUM(debit_minor) as d, SUM(credit_minor) as c')
            ->groupBy('account')
            ->orderBy('account')
            ->get()
            ->map(function ($row) {
                [$type] = Account::parse($row->account);
                $raw = (int) $row->d - (int) $row->c;

                return [
                    'account' => $row->account,
                    'label' => Account::label($row->account),
                    'nature' => Account::CHART[$type]['nature'],
                    'debit_minor' => (int) $row->d,
                    'credit_minor' => (int) $row->c,
                    'balance_minor' => Account::isDebitNormal($type) ? $raw : -$raw,
                ];
            });
    }

    /**
     * Journals whose debits and credits differ. Empty on a healthy ledger.
     *
     * @return Collection<int, object>
     */
    public function unbalancedJournals(): Collection
    {
        return LedgerEntry::query()
            ->select('journal_id')
            ->selectRaw('SUM(debit_minor) as d, SUM(credit_minor) as c')
            ->groupBy('journal_id')
            ->havingRaw('SUM(debit_minor) <> SUM(credit_minor)')
            ->get();
    }
}
