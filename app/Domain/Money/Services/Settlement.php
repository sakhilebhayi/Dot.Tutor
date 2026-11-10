<?php

namespace App\Domain\Money\Services;

use App\Domain\Money\Contracts\Purchasable;
use App\Domain\Money\Events\OrderPaid;
use App\Domain\Money\Ledger\Ledger;
use App\Domain\Money\Ledger\PostingRules;
use App\Domain\Money\Models\Earning;
use App\Domain\Money\Models\Order;
use App\Domain\Money\Models\Payment;
use App\Domain\Money\Paystack\PaystackTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Turning a verified payment into a paid order, exactly once, in one transaction: the order is marked paid,
 * the sale journal posted, each tutor's pending earning created, access granted, the coupon use confirmed and
 * the receipt issued. A payment that arrives for an order already paid or closed is kept and put on the finance
 * exceptions list for a refund decision (never silently absorbed).
 */
class Settlement
{
    public function __construct(
        private Ledger $ledger,
        private PostingRules $rules,
        private Entitlements $entitlements,
        private Coupons $coupons,
        private Receipts $receipts,
        private FinanceIssues $issues,
    ) {}

    /** An order with nothing due at a card (free, or fully covered by a discount or credit). */
    public function settle(Order $order, ?Payment $payment, ?User $actor): Order
    {
        $paid = DB::transaction(function () use ($order, $payment, $actor) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen() || ($payment === null && $locked->total_minor !== 0)) {
                return false;
            }

            $this->markPaid($locked, $payment, $actor?->id ?? $payment?->initiated_by);

            return true;
        });

        if ($paid) {
            OrderPaid::dispatch($order->id);
        }

        return $order->refresh();
    }

    public function settlePayment(Payment $payment, PaystackTransaction $transaction): Payment
    {
        $paid = DB::transaction(function () use ($payment, $transaction) {
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === Payment::PAID) {
                return false;
            }

            $locked->forceFill([
                'status' => Payment::PAID,
                'provider_transaction_id' => $transaction->id,
                'channel' => $transaction->channel,
                'fees_minor' => $transaction->feesMinor,
                'paid_at' => now(),
                'verified_at' => now(),
                'failure_code' => null,
                'failure_message' => null,
            ])->save();

            $order = Order::whereKey($locked->order_id)->lockForUpdate()->firstOrFail();

            if ($order->isOpen()) {
                $this->markPaid($order, $locked, $locked->initiated_by);

                return true;
            }

            [$kind, $summary] = $order->isSettled()
                ? ['duplicate_payment', "Order {$order->uuid} was paid twice; refund payment {$locked->reference}"]
                : ['paid_after_close', "Order {$order->uuid} was paid after it closed ({$order->status}); fulfil or refund"];

            $this->issues->raise($kind, "{$kind}:{$locked->id}", $summary, [
                'severity' => 'critical', 'subject_type' => 'payment', 'subject_id' => $locked->id,
                'amount_minor' => $locked->amount_minor, 'currency' => $locked->currency,
                'details' => ['order' => $order->uuid, 'reference' => $locked->reference],
            ]);

            return false;
        });

        if ($paid) {
            OrderPaid::dispatch($payment->order_id);
        }

        return $payment->refresh();
    }

    private function markPaid(Order $order, ?Payment $payment, ?int $payerId): void
    {
        $order->forceFill(['status' => Order::PAID, 'paid_at' => now(), 'paid_by' => $payerId])->save();
        $order->load('lines');

        if (($journal = $this->rules->orderPaid($order, $payment)) !== null) {
            $this->ledger->post($journal);
        }

        foreach ($order->lines as $line) {
            if ($line->seller_id !== null) {
                $windowDays = (int) (config('money.earnings.refund_window_days.'.$line->purchasable_type) ?? config('money.earnings.refund_window_days.default', 7));

                Earning::firstOrCreate(['source_key' => "sale:{$line->id}"], [
                    'seller_id' => $line->seller_id,
                    'order_line_id' => $line->id,
                    'kind' => Earning::KIND_SALE,
                    'gross_minor' => $line->commissionBaseMinor(),
                    'commission_minor' => $line->commission_minor,
                    'charges_minor' => $line->charges_minor,
                    'net_minor' => $line->seller_net_minor,
                    'currency' => $order->currency,
                    'status' => Earning::PENDING,
                    'release_rule' => $line->earning_release,
                    'release_after' => $line->earning_release === Purchasable::RELEASE_AFTER_WINDOW ? now()->addDays(max(0, $windowDays)) : null,
                ]);
            }

            $this->entitlements->grantForLine($line, $order->buyer_id);
        }

        $this->coupons->confirm($order->id);
        $this->receipts->issue($order, $payment);
    }
}
