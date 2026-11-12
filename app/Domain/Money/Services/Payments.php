<?php

namespace App\Domain\Money\Services;

use App\Domain\Money\CheckoutStart;
use App\Domain\Money\Exceptions\CheckoutRefused;
use App\Domain\Money\Exceptions\PaymentGatewayException;
use App\Domain\Money\Models\Order;
use App\Domain\Money\Models\Payment;
use App\Domain\Money\Paystack\PaystackClient;
use App\Domain\Money\Paystack\PaystackConfig;
use App\Domain\Money\Paystack\PaystackTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Collecting an order's total through Paystack's hosted checkout. An order is paid only from Paystack's own
 * verify answer with matching reference, amount and currency, never from the browser's return or a webhook
 * body. A second "Pay" while a checkout is open reopens the same Paystack page (one charge).
 */
class Payments
{
    public function __construct(
        private PaystackConfig $config,
        private PaystackClient $client,
        private Settlement $settlement,
        private FinanceIssues $issues,
    ) {}

    /**
     * @throws CheckoutRefused
     */
    public function start(Order $order, User $actor, ?string $returnUrl = null): CheckoutStart
    {
        if ($order->total_minor === 0) {
            $this->settlement->settle($order, null, $actor);

            return new CheckoutStart(CheckoutStart::SETTLED, $order->refresh(), message: 'Nothing to pay by card: your order is complete.');
        }

        if (! $this->config->canTakePayments()) {
            return new CheckoutStart(CheckoutStart::UNAVAILABLE, $order, message: $this->config->notice() ?? 'Online payment is not available.');
        }

        try {
            return DB::transaction(function () use ($order, $actor, $returnUrl) {
                $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

                if ($locked->isSettled()) {
                    return new CheckoutStart(CheckoutStart::SETTLED, $locked, message: 'This order is already paid.');
                }

                $open = Payment::where('order_id', $locked->id)->where('status', Payment::INITIALISED)
                    ->where('expires_at', '>', now())->orderByDesc('attempt')->first();

                if ($open !== null && $open->authorization_url !== null) {
                    return new CheckoutStart(CheckoutStart::REDIRECT, $locked, $open->authorization_url);
                }

                $attempt = ((int) Payment::where('order_id', $locked->id)->max('attempt')) + 1;
                $reference = 'tut-'.$locked->id.'-'.$attempt.'-'.Str::lower(Str::random(10));

                $payment = Payment::create([
                    'order_id' => $locked->id,
                    'provider' => 'paystack',
                    'reference' => $reference,
                    'attempt' => $attempt,
                    'amount_minor' => $locked->total_minor,
                    'currency' => $locked->currency,
                    'status' => Payment::INITIALISED,
                    'initiated_by' => $actor->id,
                    'expires_at' => now()->addMinutes($this->config->attemptTtlMinutes()),
                ]);

                // The HTTP call runs under the order lock so a double click waits and then reuses this page.
                $init = $this->client->initializeTransaction(
                    (string) $actor->email,
                    $payment->amount_minor,
                    $payment->currency,
                    $reference,
                    $returnUrl ?? route('money.checkout.return'),
                    ['order_id' => $locked->id, 'payment_id' => $payment->id],
                    config('money.paystack.channels') ?: null,
                );

                $payment->forceFill(['authorization_url' => $init->authorizationUrl])->save();

                return new CheckoutStart(CheckoutStart::REDIRECT, $locked, $init->authorizationUrl);
            });
        } catch (PaymentGatewayException $e) {
            throw CheckoutRefused::because('payment_unavailable', $e->getMessage(), 503);
        }
    }

    /**
     * Ask Paystack about one payment and apply the answer. Safe to call any number of times.
     */
    public function verify(Payment $payment): Payment
    {
        if ($payment->status === Payment::PAID || $payment->provider !== 'paystack') {
            return $payment;
        }

        try {
            $transaction = $this->client->verifyTransaction($payment->reference);
        } catch (PaymentGatewayException $e) {
            if ($e->reason === 'rejected' && $payment->expires_at !== null && $payment->expires_at->isPast()) {
                // Paystack has no such transaction and the checkout page has expired: it was never paid.
                $payment->forceFill(['status' => Payment::ABANDONED, 'failure_code' => 'not_found', 'verified_at' => now()])->save();
            }

            return $payment;
        }

        return $this->apply($payment, $transaction);
    }

    public function apply(Payment $payment, PaystackTransaction $transaction): Payment
    {
        if (! $transaction->isSuccessful()) {
            $final = in_array($transaction->status, ['failed', 'reversed'], true)
                || ($transaction->status === 'abandoned' && $payment->expires_at !== null && $payment->expires_at->isPast());

            if ($final && $payment->status === Payment::INITIALISED) {
                $payment->forceFill([
                    'status' => $transaction->status === 'abandoned' ? Payment::ABANDONED : Payment::FAILED,
                    'failure_code' => $transaction->status,
                    'failure_message' => $transaction->gatewayResponse,
                    'verified_at' => now(),
                ])->save();
            }

            return $payment;
        }

        $mismatch = match (true) {
            $transaction->reference !== $payment->reference => 'reference_mismatch',
            $transaction->currency !== $payment->currency => 'currency_mismatch',
            $transaction->amountMinor !== $payment->amount_minor => 'amount_mismatch',
            default => null,
        };

        if ($mismatch !== null) {
            $payment->forceFill(['status' => Payment::FAILED, 'failure_code' => $mismatch, 'verified_at' => now()])->save();
            $this->issues->raise('payment_mismatch', "payment-mismatch:{$payment->id}", "Paystack reported a charge that does not match payment {$payment->reference}", [
                'severity' => 'critical', 'subject_type' => 'payment', 'subject_id' => $payment->id,
                'amount_minor' => $transaction->amountMinor, 'currency' => $transaction->currency,
                'details' => ['kind' => $mismatch, 'expected_minor' => $payment->amount_minor, 'expected_currency' => $payment->currency],
            ]);

            return $payment;
        }

        return $this->settlement->settlePayment($payment, $transaction);
    }

    /**
     * Before an order is expired or cancelled, make sure none of its checkouts was in fact paid.
     */
    public function verifyOpenPayments(Order $order): void
    {
        foreach (Payment::where('order_id', $order->id)->where('status', Payment::INITIALISED)->get() as $payment) {
            $this->verify($payment);
        }
    }

    /**
     * A charge.success for a reference we never issued: confirmed with Paystack, then kept for review.
     */
    public function recordUnmatched(string $reference): string
    {
        try {
            $transaction = $this->client->verifyTransaction($reference);
        } catch (PaymentGatewayException $e) {
            return 'unmatched:unverifiable';
        }

        if (! $transaction->isSuccessful()) {
            return 'unmatched:not_successful';
        }

        $this->issues->raise('unmatched_payment', "unmatched:{$reference}", "Paystack received a payment we cannot match ({$reference})", [
            'severity' => 'critical', 'amount_minor' => $transaction->amountMinor, 'currency' => $transaction->currency,
            'details' => ['reference' => $reference, 'transaction_id' => $transaction->id, 'channel' => $transaction->channel],
        ]);

        return 'unmatched:recorded';
    }
}
