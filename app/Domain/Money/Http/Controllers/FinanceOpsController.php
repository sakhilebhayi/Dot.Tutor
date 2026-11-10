<?php

namespace App\Domain\Money\Http\Controllers;

use App\Domain\Money\Contracts\MoneyAudit;
use App\Domain\Money\Exceptions\CheckoutRefused;
use App\Domain\Money\Ledger\Ledger;
use App\Domain\Money\Models\CommissionRule;
use App\Domain\Money\Models\Coupon;
use App\Domain\Money\Models\Dispute;
use App\Domain\Money\Models\FinanceIssue;
use App\Domain\Money\Models\Order;
use App\Domain\Money\Models\OrderLine;
use App\Domain\Money\Models\Payment;
use App\Domain\Money\Models\Payout;
use App\Domain\Money\Models\PayoutBatch;
use App\Domain\Money\Models\Refund;
use App\Domain\Money\Money;
use App\Domain\Money\Purchasables;
use App\Domain\Money\Services\CommissionRules;
use App\Domain\Money\Services\Coupons;
use App\Domain\Money\Services\Credits;
use App\Domain\Money\Services\Disputes;
use App\Domain\Money\Services\Earnings;
use App\Domain\Money\Services\Entitlements;
use App\Domain\Money\Services\FinanceIssues;
use App\Domain\Money\Services\Payouts;
use App\Domain\Money\Services\Reconciliation;
use App\Domain\Money\Services\Refunds;
use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Finance operations (gate money.finance on every route): orders, refunds, exceptions, credits, commission
 * rules, platform coupons, payouts, disputes, gifts, reconciliation and the trial balance.
 */
class FinanceOpsController extends Controller
{
    public function orders(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $orders = Order::with('lines', 'receipt', 'coupon')
            ->when(is_string($status) && $status !== '', fn ($q) => $q->where('status', $status))
            ->orderByDesc('id')->limit(100)->get();

        return response()->json(['orders' => $orders->map(fn (Order $o) => $o->toApi() + ['buyer_id' => $o->buyer_id, 'internal_id' => $o->id])->all()]);
    }

    public function order(Order $order): JsonResponse
    {
        $order->load('lines.earning', 'payments', 'refunds');

        return response()->json([
            'order' => $order->toApi() + ['buyer_id' => $order->buyer_id],
            'lines' => $order->lines->map(fn (OrderLine $l) => [
                'id' => $l->id,
                'seller_id' => $l->seller_id,
                'cash_minor' => $l->cash_minor,
                'refunded_minor' => $l->refunded_minor,
                'commission_bps' => $l->commission_bps,
                'commission_minor' => $l->commission_minor,
                'charges_minor' => $l->charges_minor,
                'seller_net_minor' => $l->seller_net_minor,
                'earning_status' => $l->earning?->status,
            ])->all(),
            'payments' => $order->payments->map(fn (Payment $p) => $p->only(['id', 'reference', 'status', 'amount_minor', 'fees_minor', 'refunded_minor', 'channel', 'paid_at']))->all(),
            'refunds' => $order->refunds->map(fn (Refund $r) => $r->only(['id', 'order_line_id', 'amount_minor', 'reason', 'status', 'processed_at', 'failure_message']))->all(),
        ]);
    }

    public function refund(Request $request, Order $order, OrderLine $line, Refunds $refunds): JsonResponse
    {
        abort_unless($line->order_id === $order->id, 404);

        $data = $request->validate([
            'amount_minor' => ['required', 'integer', 'min:1'],
            'reason' => ['required', Rule::in(array_keys(Refund::REASONS))],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $refund = $refunds->request($line, $request->user(), (int) $data['amount_minor'], $data['reason'], $data['note'] ?? null, $this->idempotencyKey($request));

        return response()->json(['refund' => $refund->only(['id', 'order_line_id', 'amount_minor', 'currency', 'reason', 'status', 'failure_message', 'processed_at'])], $refund->wasRecentlyCreated ? 201 : 200);
    }

    public function refunds(): JsonResponse
    {
        return response()->json(['refunds' => Refund::orderByDesc('id')->limit(100)->get()->map(fn (Refund $r) => $r->only(['id', 'order_id', 'order_line_id', 'amount_minor', 'currency', 'reason', 'status', 'requested_by', 'processed_at', 'failure_message']))->all()]);
    }

    /** After reviewing a no-show or a cancelled-but-delivered lesson, release the tutor's earning now. */
    public function releaseLine(Request $request, Order $order, OrderLine $line, Earnings $earnings, MoneyAudit $audit): JsonResponse
    {
        abort_unless($line->order_id === $order->id, 404);
        $request->validate(['note' => ['required', 'string', 'max:500']]);

        $released = $earnings->releaseLine($line);
        $audit->record($request->user(), 'money.earning_released_manually', $line, ['note' => mb_substr((string) $request->input('note'), 0, 120)]);

        return response()->json(['released' => $released]);
    }

    public function issues(Request $request): JsonResponse
    {
        $status = $request->query('status', 'open');
        $issues = FinanceIssue::when($status !== 'all', fn ($q) => $q->where('status', $status))->orderByDesc('id')->limit(200)->get();

        return response()->json(['issues' => $issues->map(fn (FinanceIssue $i) => $i->only(['id', 'kind', 'severity', 'summary', 'subject_type', 'subject_id', 'amount_minor', 'currency', 'details', 'status', 'created_at', 'resolution_note']))->all()]);
    }

    public function resolveIssue(Request $request, FinanceIssue $issue, FinanceIssues $issues, MoneyAudit $audit): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:500']]);
        $issues->resolve($issue, $request->user(), $data['note']);
        $audit->record($request->user(), 'money.finance_issue_resolved', $issue, ['kind' => $issue->kind]);

        return response()->json(['issue' => $issue->only(['id', 'status', 'resolution_note', 'resolved_at'])]);
    }

    public function grantCredit(Request $request, Credits $credits): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'amount_minor' => ['required', 'integer', 'min:1', 'max:10000000'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $grant = $credits->grant(User::findOrFail($data['user_id']), (int) $data['amount_minor'], $data['reason'], $request->user(), $this->idempotencyKey($request));

        return response()->json(['grant' => $grant->only(['id', 'user_id', 'amount_minor', 'currency', 'reason']), 'balance' => $credits->balance((int) $data['user_id'])], $grant->wasRecentlyCreated ? 201 : 200);
    }

    public function revokeCredit(Request $request, Credits $credits): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $grant = $credits->revoke(User::findOrFail($data['user_id']), (int) $data['amount_minor'], $data['reason'], $request->user(), $this->idempotencyKey($request));

        return response()->json(['grant' => $grant->only(['id', 'user_id', 'amount_minor', 'currency', 'reason']), 'balance' => $credits->balance((int) $data['user_id'])]);
    }

    public function commissionRules(CommissionRules $rules): JsonResponse
    {
        return response()->json([
            'config_default_percent' => (float) config('money.commission.default_percent'),
            'rules' => CommissionRule::orderBy('category')->orderByDesc('version')->get()->map(fn (CommissionRule $r) => [
                'id' => $r->id, 'category' => $r->category, 'percent' => $r->percent_bps / 100, 'version' => $r->version,
                'effective_from' => $r->effective_from->toIso8601String(), 'superseded_at' => $r->superseded_at?->toIso8601String(), 'reason' => $r->reason,
            ])->all(),
        ]);
    }

    public function storeCommissionRule(Request $request, CommissionRules $rules): JsonResponse
    {
        $data = $request->validate([
            'category' => ['nullable', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/'],
            'percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'reason' => ['required', 'string', 'max:255'],
            'effective_from' => ['nullable', 'date', 'after_or_equal:now'],
        ]);

        $rule = $rules->addVersion($data['category'] ?? null, (float) $data['percent'], $data['reason'], $request->user(), isset($data['effective_from']) ? CarbonImmutable::parse($data['effective_from']) : null);

        return response()->json(['rule' => ['id' => $rule->id, 'category' => $rule->category, 'percent' => $rule->percent_bps / 100, 'version' => $rule->version]], 201);
    }

    public function storeCoupon(Request $request, Coupons $coupons): JsonResponse
    {
        return response()->json(['coupon' => $coupons->create($request->user(), Coupon::FUNDED_BY_PLATFORM, TutorMoneyController::validateCoupon($request))->toApi()], 201);
    }

    public function batches(): JsonResponse
    {
        return response()->json(['batches' => PayoutBatch::orderByDesc('id')->limit(50)->get()->map(fn (PayoutBatch $b) => $b->only(['id', 'status', 'period_end', 'currency', 'payout_count', 'total_minor', 'carried_forward', 'exported_at']))->all()]);
    }

    public function draftBatch(Request $request, Payouts $payouts): JsonResponse
    {
        $data = $request->validate(['period_end' => ['nullable', 'date', 'before_or_equal:now']]);
        $end = isset($data['period_end']) ? CarbonImmutable::parse($data['period_end'])->endOfDay()->min(now()) : now();
        $batch = $payouts->draftBatch($end, $request->user());

        return response()->json(['batch' => $batch->only(['id', 'status', 'payout_count', 'total_minor', 'carried_forward'])], 201);
    }

    public function batch(PayoutBatch $batch): JsonResponse
    {
        return response()->json([
            'batch' => $batch->only(['id', 'status', 'period_end', 'payout_count', 'total_minor', 'carried_forward', 'exported_at']),
            'payouts' => $batch->payouts()->with('account')->orderBy('id')->get()->map(fn (Payout $p) => [
                'id' => $p->id, 'payee_id' => $p->payee_id, 'reference' => $p->reference, 'status' => $p->status,
                'amount' => Money::of($p->amount_minor, $p->currency), 'method' => $p->method,
                'account_ending' => $p->account?->account_last4, 'paid_at' => $p->paid_at?->toIso8601String(), 'failure_message' => $p->failure_message,
            ])->all(),
        ]);
    }

    public function exportBatch(Request $request, PayoutBatch $batch, Payouts $payouts): Response
    {
        $csv = $payouts->exportCsv($batch, $request->user());

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="payout-batch-'.$batch->id.'.csv"',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function payoutPaid(Request $request, Payout $payout, Payouts $payouts): JsonResponse
    {
        return response()->json(['payout' => $payouts->markPaid($payout, $request->user())->only(['id', 'status', 'paid_at'])]);
    }

    public function payoutFailed(Request $request, Payout $payout, Payouts $payouts): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return response()->json(['payout' => $payouts->markFailed($payout, $request->user(), $data['reason'])->only(['id', 'status', 'failure_message'])]);
    }

    public function payoutTransfer(Request $request, Payout $payout, Payouts $payouts): JsonResponse
    {
        return response()->json(['payout' => $payouts->sendTransfer($payout, $request->user())->only(['id', 'status', 'method'])]);
    }

    public function disputes(): JsonResponse
    {
        return response()->json(['disputes' => Dispute::orderByDesc('id')->limit(100)->get()->map(fn (Dispute $d) => $d->only(['id', 'payment_id', 'order_id', 'reference', 'amount_minor', 'currency', 'status', 'reason', 'notes', 'resolved_at']))->all()]);
    }

    public function storeDispute(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reference' => ['required', 'string', 'exists:money_payments,reference'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $payment = Payment::where('reference', $data['reference'])->firstOrFail();
        $dispute = Dispute::create([
            'payment_id' => $payment->id, 'order_id' => $payment->order_id, 'reference' => $payment->reference,
            'amount_minor' => (int) $data['amount_minor'], 'currency' => $payment->currency, 'status' => 'open',
            'reason' => $data['reason'] ?? null, 'recorded_by' => $request->user()->id,
        ]);

        return response()->json(['dispute' => $dispute->only(['id', 'status', 'amount_minor'])], 201);
    }

    public function disputeOutcome(Request $request, Dispute $dispute, Disputes $disputes): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(Dispute::STATUSES)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        return response()->json(['dispute' => $disputes->setOutcome($dispute, $data['status'], $data['note'] ?? null, $request->user())->only(['id', 'status', 'resolved_at'])]);
    }

    public function gift(Request $request, Entitlements $entitlements, MoneyAudit $audit): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'type' => ['required', Rule::in(Purchasables::TYPES)],
            'id' => ['required', 'integer', 'min:1'],
        ]);

        $entitlement = $entitlements->gift(User::findOrFail($data['user_id']), $data['type'], (int) $data['id'], $request->user());
        $audit->record($request->user(), 'money.entitlement_gifted', $entitlement, ['user_id' => (int) $data['user_id'], 'type' => $data['type'], 'id' => (int) $data['id']]);

        return response()->json(['entitlement' => $entitlement->only(['id', 'user_id', 'entitled_type', 'entitled_id', 'source'])], 201);
    }

    public function reconciliation(Request $request, Reconciliation $reconciliation): JsonResponse
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $from = isset($data['from']) ? CarbonImmutable::parse($data['from'])->startOfDay() : CarbonImmutable::now()->subDays(30);
        $to = isset($data['to']) ? CarbonImmutable::parse($data['to'])->endOfDay() : CarbonImmutable::now();

        return response()->json($reconciliation->report($from, $to));
    }

    public function trialBalance(Ledger $ledger): JsonResponse
    {
        return response()->json(['currency' => Money::defaultCurrency(), 'accounts' => $ledger->trialBalance(Money::defaultCurrency())->all()]);
    }

    private function idempotencyKey(Request $request): string
    {
        $key = trim((string) $request->header('Idempotency-Key', ''));

        if ($key === '' || strlen($key) > 100) {
            throw CheckoutRefused::because('idempotency_key_required', 'Send an Idempotency-Key header so a repeated request cannot move money twice.', 428);
        }

        return $key;
    }
}
