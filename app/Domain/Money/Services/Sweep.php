<?php

namespace App\Domain\Money\Services;

use App\Domain\Money\Jobs\ProcessPaystackWebhook;
use App\Domain\Money\Models\Order;
use App\Domain\Money\Models\Payment;
use App\Domain\Money\Models\Payout;
use App\Domain\Money\Models\Refund;
use App\Domain\Money\Models\WebhookEvent;
use Throwable;

/**
 * The settlement sweep (money:settle, every five minutes): nothing is left half-done.
 *  - open checkouts are verified with Paystack (a payer who closed the tab is still credited);
 *  - stored webhooks that were not processed are processed;
 *  - unpaid orders past their time are expired (after their checkouts are verified);
 *  - due earnings are released;
 *  - refunds that could not be sent are sent; pending refunds and transfers are re-checked.
 */
class Sweep
{
    public function __construct(
        private Payments $payments,
        private Orders $orders,
        private Earnings $earnings,
        private Refunds $refunds,
        private Payouts $payouts,
    ) {}

    /**
     * @return array<string, int>
     */
    public function run(): array
    {
        $counts = ['payments_verified' => 0, 'webhooks_retried' => 0, 'orders_expired' => 0, 'earnings_released' => 0, 'refunds_retried' => 0, 'transfers_checked' => 0];

        foreach (Payment::where('status', Payment::INITIALISED)->where('created_at', '<=', now()->subMinutes(2))->orderBy('id')->limit(200)->get() as $payment) {
            $this->payments->verify($payment);
            $counts['payments_verified']++;
        }

        foreach (WebhookEvent::whereIn('status', ['received', 'failed'])->where('attempts', '<', ProcessPaystackWebhook::MAX_ATTEMPTS)
            ->where('updated_at', '<=', now()->subMinutes(5))->orderBy('id')->limit(200)->pluck('id') as $id) {
            try {
                ProcessPaystackWebhook::dispatchSync($id);
            } catch (Throwable) {
                // Recorded on the event; retried on the next sweep until the attempts run out.
            }

            $counts['webhooks_retried']++;
        }

        foreach (Order::whereIn('status', Order::OPEN)->where('expires_at', '<=', now())->orderBy('id')->limit(200)->get() as $order) {
            $this->payments->verifyOpenPayments($order);

            if ($order->refresh()->isOpen()) {
                $this->orders->close($order, Order::EXPIRED);
                $counts['orders_expired']++;
            }
        }

        $counts['earnings_released'] = $this->earnings->releaseDue();

        foreach (Refund::whereIn('status', Refund::OPEN)->where('updated_at', '<=', now()->subMinutes(10))->orderBy('id')->limit(100)->get() as $refund) {
            $refund->status === Refund::REQUESTED ? $this->refunds->submit($refund) : $this->refunds->syncFromProvider($refund);
            $counts['refunds_retried']++;
        }

        foreach (Payout::where('status', Payout::PROCESSING)->where('updated_at', '<=', now()->subMinutes(10))->limit(100)->get() as $payout) {
            $this->payouts->syncTransfer($payout);
            $counts['transfers_checked']++;
        }

        return $counts;
    }
}
