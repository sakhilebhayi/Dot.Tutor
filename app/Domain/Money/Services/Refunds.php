<?php

namespace App\Domain\Money\Services;

use App\Domain\Money\Contracts\MoneyAudit;
use App\Domain\Money\Events\OrderRefunded;
use App\Domain\Money\Exceptions\CheckoutRefused;
use App\Domain\Money\Exceptions\PaymentGatewayException;
use App\Domain\Money\Ledger\Ledger;
use App\Domain\Money\Ledger\PostingRules;
use App\Domain\Money\Models\Earning;
use App\Domain\Money\Models\Order;
use App\Domain\Money\Models\OrderLine;
use App\Domain\Money\Models\Payment;
use App\Domain\Money\Models\Refund;
use App\Domain\Money\Money;
use App\Domain\Money\Paystack\PaystackClient;
use App\Domain\Money\Paystack\PaystackConfig;
use App\Domain\Money\Paystack\PaystackRefund;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Refunds, finance operators only. Full or partial, per order line, never more than the cash paid on the line
 * minus what is refunded or already being refunded. Sent through Paystack's refund API and recorded as
 * processed only when Paystack says processed (in the answer, or by webhook then GET /refund/{id}). Processing
 * posts the reversal journal, reverses the tutor's share (pending or available), returns platform-funded
 * credit, and revokes access when the line is refunded in full. One idempotency key = one refund.
 */
class Refunds
{
    public function __construct(
        private PaystackConfig $config,
        private PaystackClient $client,
        private Ledger $ledger,
        private PostingRules $rules,
        private Entitlements $entitlements,
        private FinanceIssues $issues,
        private MoneyAudit $audit,
    ) {}

    /**
     * @throws CheckoutRefused
     */
    public function request(OrderLine $line, User $actor, int $amountMinor, string $reason, ?string $note, string $idempotencyKey): Refund
    {
        if (! Gate::forUser($actor)->allows('money.finance')) {
            throw CheckoutRefused::because('forbidden', 'Only a finance operator can refund.', 403);
        }

        if (! array_key_exists($reason, Refund::REASONS)) {
            throw CheckoutRefused::because('invalid_reason', 'Choose a refund reason.');
        }

        $key = hash('sha256', 'refund|'.$actor->id.'|'.trim($idempotencyKey));

        if (($existing = Refund::where('idempotency_key', $key)->first()) !== null) {
            return $existing;
        }

        try {
            $refund = DB::transaction(function () use ($line, $actor, $amountMinor, $reason, $note, $key) {
                $lockedLine = OrderLine::whereKey($line->id)->lockForUpdate()->firstOrFail();
                $order = Order::whereKey($lockedLine->order_id)->lockForUpdate()->firstOrFail();

                if (! $order->isSettled()) {
                    throw CheckoutRefused::because('not_paid', 'Only a paid order can be refunded.', 409);
                }

                $payment = Payment::where('order_id', $order->id)->where('status', Payment::PAID)->orderBy('id')->first();

                if ($payment === null) {
                    throw CheckoutRefused::because('nothing_paid_by_card', 'Nothing was paid by card on this order, so there is no money to return.', 409);
                }

                $open = (int) Refund::where('order_line_id', $lockedLine->id)->whereIn('status', Refund::OPEN)->sum('amount_minor');
                $left = $lockedLine->refundableMinor() - $open;

                if ($amountMinor < 1 || $amountMinor > $left) {
                    throw CheckoutRefused::because('invalid_amount', 'The refund must be between '.Money::of(1, $order->currency)->format().' and '.Money::of(max(0, $left), $order->currency)->format().'.');
                }

                return Refund::create([
                    'order_id' => $order->id,
                    'order_line_id' => $lockedLine->id,
                    'payment_id' => $payment->id,
                    'amount_minor' => $amountMinor,
                    'currency' => $order->currency,
                    'reason' => $reason,
                    'note' => $note === null ? null : mb_substr($note, 0, 500),
                    'status' => Refund::REQUESTED,
                    'idempotency_key' => $key,
                    'requested_by' => $actor->id,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            return Refund::where('idempotency_key', $key)->firstOrFail();
        }

        $this->audit->record($actor, 'money.refund_requested', $refund, ['order_line_id' => $line->id, 'amount_minor' => $amountMinor, 'reason' => $reason]);

        return $this->submit($refund);
    }

    /**
     * Send a requested refund to Paystack. If Paystack cannot be reached nothing was sent: it stays requested
     * and the settlement sweep tries again.
     */
    public function submit(Refund $refund): Refund
    {
        if ($refund->status !== Refund::REQUESTED) {
            return $refund;
        }

        if (! $this->config->canTakePayments()) {
            $refund->forceFill(['failure_message' => 'Paystack is not enabled on this environment; the refund has not been sent.'])->save();

            return $refund;
        }

        $payment = $refund->payment;

        try {
            $remote = $this->client->createRefund($payment->reference, $refund->amount_minor, $refund->currency, Refund::REASONS[$refund->reason]);
        } catch (PaymentGatewayException $e) {
            if ($e->reason === 'unavailable') {
                $refund->forceFill(['failure_message' => mb_substr($e->getMessage(), 0, 255)])->save();

                return $refund;
            }

            return $this->markFailed($refund, $e->getMessage());
        }

        $refund->forceFill(['provider_refund_id' => $remote->id, 'status' => Refund::PENDING, 'failure_message' => null])->save();

        return $this->applyRemote($refund, $remote);
    }

    /** Fetch the refund from Paystack (never trusting a webhook body) and apply it. */
    public function syncFromProvider(Refund $refund): Refund
    {
        if ($refund->provider_refund_id === null || ! $refund->isOpen()) {
            return $refund;
        }

        try {
            $remote = $this->client->fetchRefund($refund->provider_refund_id);
        } catch (PaymentGatewayException) {
            return $refund;
        }

        return $this->applyRemote($refund, $remote);
    }

    public function applyRemote(Refund $refund, PaystackRefund $remote): Refund
    {
        if ($remote->amountMinor !== $refund->amount_minor) {
            $this->issues->raise('refund_mismatch', "refund-amount:{$refund->id}", "Refund #{$refund->id}: Paystack reports a different amount", [
                'severity' => 'critical', 'subject_type' => 'refund', 'subject_id' => $refund->id, 'amount_minor' => $remote->amountMinor, 'currency' => $refund->currency,
            ]);

            return $refund;
        }

        return match ($remote->status) {
            'processed' => $this->markProcessed($refund),
            'failed', 'needs-attention' => $this->markFailed($refund, 'Paystack could not complete the refund ('.$remote->status.').'),
            'processing' => tap($refund)->forceFill(['status' => Refund::PROCESSING])->save(),
            default => $refund,
        };
    }

    private function markProcessed(Refund $refund): Refund
    {
        $result = DB::transaction(function () use ($refund) {
            $locked = Refund::whereKey($refund->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === Refund::PROCESSED) {
                return null;
            }

            $line = OrderLine::whereKey($locked->order_line_id)->lockForUpdate()->firstOrFail();
            $order = Order::whereKey($locked->order_id)->lockForUpdate()->firstOrFail();
            $payment = Payment::whereKey($locked->payment_id)->lockForUpdate()->firstOrFail();
            $sale = Earning::where('source_key', "sale:{$line->id}")->lockForUpdate()->first();
            $tutorFundsAvailable = $sale !== null && in_array($sale->status, [Earning::AVAILABLE, Earning::PAID], true);

            ['entry' => $entry, 'tutor_minor' => $tutorBack] = $this->rules->refundProcessed($locked, $line, $order, $tutorFundsAvailable);
            $journal = $this->ledger->post($entry);

            if ($sale !== null && $tutorBack > 0) {
                Earning::firstOrCreate(['source_key' => "refund:{$locked->id}"], [
                    'seller_id' => $sale->seller_id,
                    'order_line_id' => $line->id,
                    'kind' => Earning::KIND_REVERSAL,
                    'gross_minor' => 0,
                    'commission_minor' => 0,
                    'charges_minor' => 0,
                    'net_minor' => -$tutorBack,
                    'currency' => $locked->currency,
                    'status' => $tutorFundsAvailable ? Earning::AVAILABLE : Earning::PENDING,
                    'release_rule' => $sale->release_rule,
                    'release_after' => $sale->release_after,
                    'available_at' => $tutorFundsAvailable ? now() : null,
                ]);
            }

            $locked->forceFill(['status' => Refund::PROCESSED, 'processed_at' => now(), 'failure_message' => null])->save();
            $line->forceFill(['refunded_minor' => $line->refunded_minor + $locked->amount_minor])->save();
            $payment->forceFill(['refunded_minor' => $payment->refunded_minor + $locked->amount_minor])->save();
            $order->forceFill([
                'refunded_minor' => $order->refunded_minor + $locked->amount_minor,
                'status' => $order->refunded_minor + $locked->amount_minor >= $order->total_minor ? Order::REFUNDED : Order::PARTIALLY_REFUNDED,
            ])->save();

            $full = $line->refundableMinor() === 0;

            if ($full) {
                $this->entitlements->revokeForLine($line, 'Refunded: '.(Refund::REASONS[$locked->reason] ?? $locked->reason));
            }

            return [$order->id, $line->id, $locked->id, $full, $journal->id];
        });

        if ($result !== null) {
            OrderRefunded::dispatch($result[0], $result[1], $result[2], $result[3]);
        }

        return $refund->refresh();
    }

    private function markFailed(Refund $refund, string $message): Refund
    {
        $refund->forceFill(['status' => Refund::FAILED, 'failure_message' => mb_substr($message, 0, 255)])->save();

        $this->issues->raise('refund_failed', "refund-failed:{$refund->id}", "Refund #{$refund->id} failed: {$message}", [
            'subject_type' => 'refund', 'subject_id' => $refund->id, 'amount_minor' => $refund->amount_minor, 'currency' => $refund->currency,
        ]);

        return $refund;
    }
}
