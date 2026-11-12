<?php

namespace App\Domain\Comms\Listeners;

use App\Domain\Comms\CommsMessage;
use App\Domain\Comms\Messenger;
use App\Domain\Money\Events\OrderPaid;
use App\Domain\Money\Events\OrderRefunded;
use App\Domain\Money\Models\Order;
use App\Domain\Money\Models\Payment;
use App\Domain\Money\Models\Payout;
use App\Domain\Money\Models\Receipt;
use App\Domain\Money\Models\Refund;
use App\Domain\Money\Money;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Payments, refunds, sales and payouts. Listens to the money domain's own events (OrderPaid, OrderRefunded, both
 * dispatched after commit) and observes Payment and Payout rows for the states money raises no event for (a
 * declined payment, a payout drafted, paid or failed). Observers run after commit and never change money rows.
 * A minor's purchases are copied to their guardians.
 */
final class MoneyNotifications
{
    public bool $afterCommit = true;

    public function __construct(private readonly Messenger $m) {}

    public function orderPaid(OrderPaid $event): void
    {
        try {
            $order = Order::query()->with(['buyer', 'lines'])->find($event->orderId);
            $buyer = $order?->buyer;

            if ($order === null || $buyer === null) {
                return;
            }

            $receipt = Receipt::query()->where('order_id', $order->id)->first();
            $amount = Money::of((int) $order->total_minor, $order->currency)->format();
            $items = $order->lines->pluck('title')->filter()->take(3)->implode(', ');
            $url = $receipt ? route('money.receipts.show', $receipt, false) : null;
            $data = ['order_id' => $order->id, 'receipt_id' => $receipt?->id, 'action_label' => $receipt ? 'View the receipt' : null];

            $this->m->learnerAndGuardians($buyer,
                new CommsMessage($buyer, 'payment_received', 'order:'.$order->id.':paid', 'Payment received',
                    ($order->total_minor > 0 ? 'We received '.$amount.' for ' : 'Your order is complete: ').$items.'.'.($receipt ? ' Receipt '.$receipt->number.'.' : ''),
                    $url, 'order-'.$order->id, $order->paid_by, data: array_filter($data, fn ($v) => $v !== null)),
                fn (User $g) => [$this->m->firstName($buyer).'\'s purchase is paid', ($order->total_minor > 0 ? $amount.' was paid for ' : 'Completed: ').$items.'.']);

            // Sellers of digital items hear about the sale (lessons are announced as bookings). The buyer is not named.
            foreach ($order->lines->whereNotIn('purchasable_type', ['booking', 'offering', 'package', 'tutor_session', 'tutor_session_participant', 'tutor_package_purchase']) as $line) {
                $seller = $line->seller_id ? User::find($line->seller_id) : null;
                if ($seller === null || $seller->id === $buyer->id) {
                    continue;
                }
                $this->m->send(new CommsMessage($seller, 'sale_made', 'order-line:'.$line->id.':sold', 'You made a sale',
                    'Someone bought "'.$line->title.'". Your earning of '.Money::of((int) $line->seller_net_minor, $order->currency)->format().' is pending until the refund window ends.',
                    route('money.earnings', absolute: false), 'sales-'.$seller->id, null, replaceUnread: false, data: ['order_line_id' => $line->id]));
            }
        } catch (Throwable $e) {
            Log::warning('Comms could not notify a payment.', ['order_id' => $event->orderId, 'error' => class_basename($e)]);
        }
    }

    public function orderRefunded(OrderRefunded $event): void
    {
        try {
            $refund = Refund::query()->find($event->refundId);
            $order = Order::query()->with('buyer')->find($event->orderId);
            $buyer = $order?->buyer;

            if ($refund === null || $buyer === null) {
                return;
            }

            $amount = Money::of((int) $refund->amount_minor, $refund->currency)->format();
            $this->m->learnerAndGuardians($buyer,
                new CommsMessage($buyer, 'refund_processed', 'refund:'.$refund->id, 'Refund processed',
                    $amount.' was refunded to the card you paid with. Banks usually show it within 5 to 10 working days.'.($event->fullyRefunded ? ' Access to the item has ended.' : ''),
                    null, 'order-'.$order->id, null, data: ['order_id' => $order->id, 'refund_id' => $refund->id]),
                fn (User $g) => ['Refund for '.$this->m->firstName($buyer).'\'s purchase', $amount.' was refunded to the card used to pay.']);
        } catch (Throwable $e) {
            Log::warning('Comms could not notify a refund.', ['refund_id' => $event->refundId, 'error' => class_basename($e)]);
        }
    }

    /** Payment observer: a checkout that the payment provider declined. */
    public function paymentUpdated(Payment $payment): void
    {
        try {
            if (! $payment->wasChanged('status') || $payment->status !== Payment::FAILED) {
                return;
            }

            $order = Order::query()->with('buyer')->find($payment->order_id);
            $buyer = $order?->buyer;

            if ($buyer === null) {
                return;
            }

            $this->m->learnerAndGuardians($buyer,
                new CommsMessage($buyer, 'payment_failed', 'payment:'.$payment->id.':failed', 'Your payment did not go through',
                    'Nothing was charged for this attempt. You can try again from the checkout page while the order is open.',
                    route('money.checkout.show', ['order' => $order->uuid], false), 'order-'.$order->id, null,
                    data: ['order_id' => $order->id, 'action_label' => 'Try again']),
                fn (User $g) => [$this->m->firstName($buyer).'\'s payment did not go through', 'Nothing was charged for that attempt.']);
        } catch (Throwable $e) {
            Log::warning('Comms could not notify a failed payment.', ['payment_id' => $payment->id, 'error' => class_basename($e)]);
        }
    }

    public function payoutCreated(Payout $payout): void
    {
        $this->payout($payout, 'drafted');
    }

    public function payoutUpdated(Payout $payout): void
    {
        if ($payout->wasChanged('status') && in_array($payout->status, [Payout::PAID, Payout::FAILED], true)) {
            $this->payout($payout, $payout->status);
        }
    }

    private function payout(Payout $payout, string $state): void
    {
        try {
            $payee = User::find($payout->payee_id);

            if ($payee === null || ($state === 'drafted' && $payout->status !== Payout::DRAFT)) {
                return;
            }

            $amount = Money::of((int) $payout->amount_minor, $payout->currency)->format();
            [$type, $title, $body] = match ($state) {
                'drafted' => ['payout_drafted', 'Payout being prepared', 'A payout of '.$amount.' (reference '.$payout->reference.') is in this week\'s batch. You will be told when it is paid.'],
                Payout::PAID => ['payout_paid', 'Payout paid', $amount.' was paid to your bank account (reference '.$payout->reference.'). Banks can take up to 2 working days to show it.'],
                default => ['payout_failed', 'Payout did not go through', 'The payout of '.$amount.' (reference '.$payout->reference.') failed. The money stays in your available earnings and goes in the next batch; check your bank details.'],
            };

            $this->m->send(new CommsMessage($payee, $type, 'payout:'.$payout->id.':'.$state, $title, $body,
                route('money.earnings', absolute: false), 'payout-'.$payout->id, null, data: ['payout_id' => $payout->id]));
        } catch (Throwable $e) {
            Log::warning('Comms could not notify a payout.', ['payout_id' => $payout->id, 'error' => class_basename($e)]);
        }
    }
}
