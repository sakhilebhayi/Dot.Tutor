<?php

namespace App\Domain\Money\Webhooks;

use App\Domain\Money\Models\Payment;
use App\Domain\Money\Models\Payout;
use App\Domain\Money\Models\Refund;
use App\Domain\Money\Models\WebhookEvent;
use App\Domain\Money\Services\Disputes;
use App\Domain\Money\Services\Payments;
use App\Domain\Money\Services\Payouts;
use App\Domain\Money\Services\Refunds;

/**
 * Applies one stored webhook. Nothing is trusted from the body beyond "look at this object": every money
 * state change is made from Paystack's own answer (transaction verify, refund fetch, transfer verify).
 */
class PaystackWebhookProcessor
{
    public function __construct(
        private Payments $payments,
        private Refunds $refunds,
        private Payouts $payouts,
        private Disputes $disputes,
    ) {}

    public function process(WebhookEvent $event): string
    {
        $data = (array) (((array) $event->payload)['data'] ?? []);

        return match (true) {
            $event->event === 'charge.success' => $this->charge($data),
            str_starts_with($event->event, 'refund.') => $this->refund($data),
            str_starts_with($event->event, 'transfer.') => $this->transfer($data),
            str_starts_with($event->event, 'charge.dispute.') => $this->disputes->recordFromWebhook($event->event, $data),
            default => 'ignored',
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function charge(array $data): string
    {
        $reference = is_string($data['reference'] ?? null) ? $data['reference'] : null;

        if ($reference === null) {
            return 'charge:no_reference';
        }

        $payment = Payment::where('reference', $reference)->first();

        if ($payment === null) {
            return $this->payments->recordUnmatched($reference);
        }

        return 'charge:'.$this->payments->verify($payment)->status;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function refund(array $data): string
    {
        $id = $data['id'] ?? $data['refund_reference'] ?? null;
        $refund = is_scalar($id) ? Refund::where('provider_refund_id', (string) $id)->first() : null;

        if ($refund === null) {
            $reference = $data['transaction_reference'] ?? ($data['transaction']['reference'] ?? null);
            $payment = is_string($reference) ? Payment::where('reference', $reference)->first() : null;
            $refund = $payment === null ? null : Refund::where('payment_id', $payment->id)->whereIn('status', Refund::OPEN)->orderByDesc('id')->first();
        }

        return $refund === null ? 'refund:unknown' : 'refund:'.$this->refunds->syncFromProvider($refund)->status;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function transfer(array $data): string
    {
        $reference = is_string($data['reference'] ?? null) ? $data['reference'] : null;
        $payout = $reference === null ? null : Payout::where('reference', $reference)->first();

        return $payout === null ? 'transfer:unknown' : 'transfer:'.$this->payouts->syncTransfer($payout)->status;
    }
}
