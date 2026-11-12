<?php

namespace App\Domain\Ops\Http\Controllers;

use App\Domain\Money\Exceptions\CheckoutRefused;
use App\Domain\Money\Models\CommissionRule;
use App\Domain\Money\Models\Dispute;
use App\Domain\Money\Models\FinanceIssue;
use App\Domain\Money\Models\Order;
use App\Domain\Money\Models\OrderLine;
use App\Domain\Money\Models\Payout;
use App\Domain\Money\Models\PayoutBatch;
use App\Domain\Money\Models\Refund;
use App\Domain\Money\Contracts\MoneyAudit;
use App\Domain\Money\Services\Disputes;
use App\Domain\Money\Services\Earnings;
use App\Domain\Money\Services\FinanceIssues;
use App\Domain\Money\Services\Payouts;
use App\Domain\Money\Services\Refunds;
use App\Domain\Ops\Access\OpsPermission as P;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Marketplace money (brief sections 17 and 20): orders and payments, commission rules in force, refunds (finance
 * only, through Paystack via the money Refunds service), card disputes, finance exceptions including unmatched
 * payments, and payout batches. Every action is the money domain's own service; each one writes the audit log.
 * Refund forms carry a one-time idempotency key, so a double submit cannot refund twice.
 */
class MoneyController extends OpsController
{
    public const TABS = ['orders' => 'Orders and payments', 'refunds' => 'Refunds', 'exceptions' => 'Exceptions and unmatched payments', 'disputes' => 'Card disputes', 'commission' => 'Commission'];

    public function payments(Request $request): View
    {
        $tab = array_key_exists((string) $request->query('tab'), self::TABS) ? $request->query('tab') : 'orders';
        $status = in_array($request->query('status'), [...Order::OPEN, ...Order::SETTLED, Order::CANCELLED, Order::EXPIRED, Order::GUARDIAN_REFUSED], true) ? $request->query('status') : null;

        return view('ops.money.payments', [
            'tab' => $tab,
            'status' => $status,
            'counts' => [
                'refunds' => Refund::query()->whereIn('status', [...Refund::OPEN, Refund::FAILED])->count(),
                'exceptions' => FinanceIssue::query()->where('status', 'open')->count(),
                'disputes' => Dispute::query()->where('status', 'open')->count(),
            ],
            'orders' => $tab === 'orders' ? Order::query()->with(['lines', 'buyer:id,name'])->when($status, fn ($q, $s) => $q->where('status', $s))->orderByDesc('id')->paginate(30)->withQueryString() : null,
            'refunds' => $tab === 'refunds' ? Refund::query()->orderByRaw("case when status in ('requested','pending','processing','failed') then 0 else 1 end")->orderByDesc('id')->paginate(30)->withQueryString() : null,
            'issues' => $tab === 'exceptions' ? FinanceIssue::query()->orderByRaw("case when status = 'open' then 0 else 1 end")
                ->orderByRaw("case when severity = 'critical' then 0 else 1 end")->orderByDesc('id')->paginate(30)->withQueryString() : null,
            'disputes' => $tab === 'disputes' ? Dispute::query()->orderByRaw("case when status = 'open' then 0 else 1 end")->orderByDesc('id')->paginate(30)->withQueryString() : null,
            'rules' => $tab === 'commission' ? CommissionRule::query()->orderBy('category')->orderByDesc('version')->get() : null,
            'defaultPercent' => (float) config('money.commission.default_percent'),
            'paystack' => [
                'configured' => filled(config('money.paystack.secret_key')),
            ],
            'canManage' => $this->can(P::FINANCE_MANAGE),
        ]);
    }

    public function order(Order $order): View
    {
        $order->load(['lines.earning', 'payments', 'refunds', 'buyer:id,name,email', 'receipt']);

        return view('ops.money.order', [
            'order' => $order,
            'sellers' => DB::table('users')->whereIn('id', $order->lines->pluck('seller_id')->filter())->pluck('name', 'id'),
            'reasons' => Refund::REASONS,
            'canRefund' => $this->can(P::FINANCE_REFUND),
            'canManage' => $this->can(P::FINANCE_MANAGE),
        ]);
    }

    public function refund(Request $request, Order $order, OrderLine $line, Refunds $refunds): RedirectResponse
    {
        abort_unless($line->order_id === $order->id, 404);

        $data = $request->validate([
            'amount' => ['required', 'regex:/^\d{1,7}([.,]\d{1,2})?$/'],
            'reason' => ['required', Rule::in(array_keys(Refund::REASONS))],
            'note' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['required', 'string', 'uuid'],
        ]);

        try {
            $refund = $refunds->request($line, $request->user(), $this->toMinor($data['amount']), $data['reason'], $data['note'] ?? null, 'ops-form:'.$data['idempotency_key']);
        } catch (CheckoutRefused $e) {
            return $this->refused($e->getMessage());
        }

        return $this->done(match ($refund->status) {
            Refund::PROCESSED => 'Refund processed by Paystack.',
            Refund::FAILED => 'Paystack refused the refund: '.($refund->failure_message ?: 'no reason given').'. It is listed under exceptions.',
            default => 'Refund sent to Paystack. It counts as processed only when Paystack confirms it.',
        });
    }

    /** After reviewing a no-show or a cancelled-but-delivered lesson, release the tutor's earning now. */
    public function release(Request $request, Order $order, OrderLine $line, Earnings $earnings, MoneyAudit $audit): RedirectResponse
    {
        abort_unless($line->order_id === $order->id, 404);
        $data = $request->validate(['note' => ['required', 'string', 'max:500']]);

        $released = $earnings->releaseLine($line);
        $audit->record($request->user(), 'money.earning_released_manually', $line, ['note' => mb_substr($data['note'], 0, 120)]);

        return $released ? $this->done('Earning released to the tutor\'s available balance.') : $this->refused('Nothing was waiting to be released on this line.');
    }

    public function resolveIssue(Request $request, FinanceIssue $issue, FinanceIssues $issues, MoneyAudit $audit): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:500']]);
        $issues->resolve($issue, $request->user(), $data['note']);
        $audit->record($request->user(), 'money.finance_issue_resolved', $issue, ['kind' => $issue->kind]);

        return $this->done('Exception resolved with your note.');
    }

    public function disputeOutcome(Request $request, Dispute $dispute, Disputes $disputes): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(Dispute::STATUSES)], 'note' => ['nullable', 'string', 'max:500']]);

        try {
            $disputes->setOutcome($dispute, $data['status'], $data['note'] ?? null, $request->user());
        } catch (CheckoutRefused $e) {
            return $this->refused($e->getMessage());
        }

        return $this->done($data['status'] === 'lost' ? 'Recorded as lost; the loss is posted to the ledger once.' : 'Outcome recorded.');
    }

    public function payouts(Payouts $payouts): View
    {
        return view('ops.money.payouts', [
            'batches' => PayoutBatch::query()->orderByDesc('id')->paginate(20),
            'available' => (int) DB::table('money_earnings')->where('status', 'available')->whereNull('payout_id')->sum('net_minor'),
            'pending' => (int) DB::table('money_earnings')->where('status', 'pending')->sum('net_minor'),
            'minimum' => (int) config('money.payouts.minimum_minor', 10000),
            'payoutDay' => \Carbon\Carbon::create(2024, 1, 1)->addDays(((int) config('money.payouts.schedule_day', 1) + 6) % 7)->format('l'),
            'transfers' => $payouts->transfersEnabled(),
            'canPayout' => $this->can(P::FINANCE_PAYOUTS),
        ]);
    }

    public function batch(PayoutBatch $batch): View
    {
        return view('ops.money.batch', [
            'batch' => $batch,
            'payouts' => $batch->payouts()->with('account')->orderBy('id')->get(),
            'payees' => DB::table('users')->whereIn('id', $batch->payouts()->pluck('payee_id'))->pluck('name', 'id'),
            'canPayout' => $this->can(P::FINANCE_PAYOUTS),
        ]);
    }

    public function draftBatch(Request $request, Payouts $payouts): RedirectResponse
    {
        $data = $request->validate(['period_end' => ['nullable', 'date', 'before_or_equal:today']]);
        $end = isset($data['period_end']) ? CarbonImmutable::parse($data['period_end'])->endOfDay()->min(now()) : now();
        $batch = $payouts->draftBatch($end, $request->user());

        return redirect()->route('ops.payouts.batch', $batch, 303)->with('success', $batch->payout_count === 0 ? 'Batch drafted: no tutor had enough available to pay this time.' : 'Batch drafted with '.$batch->payout_count.' payouts.');
    }

    public function payoutPaid(Request $request, Payout $payout, Payouts $payouts): RedirectResponse
    {
        try {
            $payouts->markPaid($payout, $request->user());
        } catch (CheckoutRefused $e) {
            return $this->refused($e->getMessage());
        }

        return $this->done('Marked paid. The tutor\'s statement now shows it.');
    }

    public function payoutFailed(Request $request, Payout $payout, Payouts $payouts): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $payouts->markFailed($payout, $request->user(), $data['reason']);

        return $this->done('Marked failed. The earnings return to the next batch.');
    }

    private function toMinor(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', str_replace(',', '.', $amount), 2), 2, '0');

        return (int) $whole * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }
}
