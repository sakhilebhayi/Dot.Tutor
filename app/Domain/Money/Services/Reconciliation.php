<?php

namespace App\Domain\Money\Services;

use App\Domain\Money\Ledger\Account;
use App\Domain\Money\Ledger\Ledger;
use App\Domain\Money\Ledger\Models\LedgerEntry;
use App\Domain\Money\Models\Earning;
use App\Domain\Money\Models\Order;
use App\Domain\Money\Models\Payment;
use App\Domain\Money\Models\Payout;
use App\Domain\Money\Models\Refund;
use App\Domain\Money\Money;
use Carbon\CarbonInterface;

/**
 * Reconciliation for finance:
 *  1. internal: every paid order, processed refund and paid payout has its journal; every journal balances;
 *     each tutor's pending and available earnings equal their ledger balances; each learner's credit is never
 *     negative;
 *  2. gateway: a Paystack transactions export (columns "reference" and "amount" in rand) against our verified
 *     payments.
 * Mismatches can be recorded on the finance exceptions list.
 */
class Reconciliation
{
    public function __construct(private Ledger $ledger, private FinanceIssues $issues) {}

    /**
     * @return array<string, mixed>
     */
    public function report(CarbonInterface $from, CarbonInterface $to, bool $recordIssues = false): array
    {
        $currency = Money::defaultCurrency();
        $mismatches = [];

        foreach (Order::whereIn('status', Order::SETTLED)->whereBetween('paid_at', [$from, $to])->with('lines')->get() as $order) {
            $base = (int) $order->lines->sum(fn ($l) => $l->commissionBaseMinor());

            if ($base > 0 && ! $this->ledger->hasPosted("order-paid:{$order->id}")) {
                $mismatches[] = ['kind' => 'order_not_posted', 'subject_id' => $order->id, 'amount_minor' => $base];
            }
        }

        foreach (Refund::where('status', Refund::PROCESSED)->whereBetween('processed_at', [$from, $to])->get() as $refund) {
            if (! $this->ledger->hasPosted("refund:{$refund->id}")) {
                $mismatches[] = ['kind' => 'refund_not_posted', 'subject_id' => $refund->id, 'amount_minor' => $refund->amount_minor];
            }
        }

        foreach (Payout::where('status', Payout::PAID)->whereBetween('paid_at', [$from, $to])->get() as $payout) {
            if (! $this->ledger->hasPosted("payout:{$payout->id}")) {
                $mismatches[] = ['kind' => 'payout_not_posted', 'subject_id' => $payout->id, 'amount_minor' => $payout->amount_minor];
            }
        }

        foreach ($this->ledger->unbalancedJournals() as $row) {
            $mismatches[] = ['kind' => 'journal_unbalanced', 'subject_id' => (int) $row->journal_id, 'amount_minor' => (int) $row->d - (int) $row->c];
        }

        foreach ($this->earningsAgainstLedger($currency) as $row) {
            $mismatches[] = $row;
        }

        $trial = $this->ledger->trialBalance($currency);

        foreach ($trial->filter(fn ($r) => str_starts_with($r['account'], Account::LEARNER_CREDIT.':') && $r['balance_minor'] < 0) as $row) {
            $mismatches[] = ['kind' => 'credit_negative', 'subject_id' => (int) explode(':', $row['account'])[1], 'amount_minor' => $row['balance_minor']];
        }

        if ($recordIssues) {
            foreach ($mismatches as $m) {
                $this->issues->raise('reconciliation', 'recon:'.$m['kind'].':'.$m['subject_id'], str_replace('_', ' ', ucfirst($m['kind'])).' (#'.$m['subject_id'].')', [
                    'severity' => 'critical', 'amount_minor' => $m['amount_minor'] ?? null, 'currency' => $currency, 'details' => $m,
                ]);
            }
        }

        $payments = Payment::where('status', Payment::PAID)->whereBetween('paid_at', [$from, $to]);

        return [
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'currency' => $currency,
            'verified_payments' => ['count' => (clone $payments)->count(), 'amount_minor' => (int) (clone $payments)->sum('amount_minor')],
            'ledger' => [
                'total_debits_minor' => (int) $trial->sum('debit_minor'),
                'total_credits_minor' => (int) $trial->sum('credit_minor'),
                'balanced' => (int) $trial->sum('debit_minor') === (int) $trial->sum('credit_minor'),
                'gateway_clearing_minor' => $this->ledger->balance(Account::GATEWAY_CLEARING, $currency),
            ],
            'mismatches' => $mismatches,
        ];
    }

    /**
     * Each tutor's pending earnings must equal tutor_pending:{id}, and available (unpaid) earnings tutor_payable:{id}.
     *
     * @return list<array<string, mixed>>
     */
    public function earningsAgainstLedger(string $currency): array
    {
        $out = [];
        $sums = Earning::where('currency', $currency)->whereIn('status', [Earning::PENDING, Earning::AVAILABLE])
            ->selectRaw('seller_id, status, SUM(net_minor) as total')->groupBy('seller_id', 'status')->get();
        $sellers = $sums->pluck('seller_id')->merge(
            LedgerEntry::whereIn('account_type', [Account::TUTOR_PENDING, Account::TUTOR_PAYABLE])->distinct()->pluck('party_id')
        )->unique();

        foreach ($sellers as $sellerId) {
            foreach ([Earning::PENDING => Account::tutorPending((int) $sellerId), Earning::AVAILABLE => Account::tutorPayable((int) $sellerId)] as $status => $account) {
                $expected = (int) ($sums->first(fn ($r) => (int) $r->seller_id === (int) $sellerId && $r->status === $status)?->total ?? 0);
                $posted = $this->ledger->balance($account, $currency);

                if ($expected !== $posted) {
                    $out[] = ['kind' => "earnings_{$status}_differs", 'subject_id' => (int) $sellerId, 'amount_minor' => $expected, 'posted_minor' => $posted];
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     * @return array{matched: int, mismatches: list<array<string, mixed>>}
     */
    public function compareSettlement(array $rows, bool $recordIssues = false): array
    {
        $matched = 0;
        $mismatches = [];

        foreach ($rows as $row) {
            $reference = trim((string) ($row['reference'] ?? ''));

            if ($reference === '') {
                continue;
            }

            try {
                $amount = Money::fromDecimal(str_replace([' ', ','], '', (string) ($row['amount'] ?? '0')))->minor;
            } catch (\InvalidArgumentException) {
                $mismatches[] = ['kind' => 'unreadable_amount', 'reference' => $reference];

                continue;
            }

            $payment = Payment::where('reference', $reference)->first();

            if ($payment === null) {
                $mismatches[] = ['kind' => 'settled_but_unknown', 'reference' => $reference, 'amount_minor' => $amount];
            } elseif ($payment->status !== Payment::PAID) {
                $mismatches[] = ['kind' => 'settled_but_not_paid_here', 'reference' => $reference, 'status' => $payment->status, 'amount_minor' => $amount];
            } elseif ($payment->amount_minor !== $amount) {
                $mismatches[] = ['kind' => 'settled_amount_differs', 'reference' => $reference, 'amount_minor' => $amount, 'ours_minor' => $payment->amount_minor];
            } else {
                $matched++;
            }
        }

        if ($recordIssues) {
            foreach ($mismatches as $m) {
                $this->issues->raise('reconciliation', 'settlement:'.$m['kind'].':'.$m['reference'], str_replace('_', ' ', ucfirst($m['kind'])).' ('.$m['reference'].')', [
                    'severity' => 'critical', 'amount_minor' => $m['amount_minor'] ?? null, 'currency' => Money::defaultCurrency(), 'details' => $m,
                ]);
            }
        }

        return ['matched' => $matched, 'mismatches' => $mismatches];
    }
}
