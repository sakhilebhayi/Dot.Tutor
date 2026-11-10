<?php

namespace App\Domain\Integration\Listeners;

use App\Domain\Marketplace\Models\TutorSessionEvent;
use App\Domain\Money\Models\Order;
use App\Domain\Money\Models\OrderLine;
use App\Domain\Money\Money;
use App\Domain\Money\Services\FinanceIssues;
use App\Domain\Marketplace\Models\TutorSessionParticipant;
use App\Models\TutorSession;

/**
 * The marketplace computes what is due back when a paid lesson or seat is cancelled, resolved in a dispute, or
 * paid after its hold ran out (refund_due_minor on the booking event). Refunds are a finance decision in the money
 * domain (finance operators only, sent through Paystack), so each amount due becomes a finance issue naming the
 * order line and the amount; finance refunds it with the existing refund action.
 */
class RaiseRefundDue
{
    public function __construct(private readonly FinanceIssues $issues) {}

    public function handle(TutorSessionEvent $event): void
    {
        $due = (int) ($event->context['refund_due_minor'] ?? 0);

        if ($due <= 0) {
            return;
        }

        [$type, $id, $reference] = $event->participant_id !== null
            ? ['seat', (int) $event->participant_id, TutorSessionParticipant::query()->whereKey($event->participant_id)->value('order_reference')]
            : ['booking', (int) $event->tutor_session_id, TutorSession::query()->whereKey($event->tutor_session_id)->value('order_reference')];

        $reference = $event->context['order_reference'] ?? $reference;
        $order = $reference ? Order::query()->where('uuid', $reference)->first() : null;

        if ($order === null || ! $order->isSettled()) {
            return; // nothing was paid through money
        }

        $line = OrderLine::query()->where('order_id', $order->id)->where('purchasable_type', $type)->where('purchasable_id', $id)->first();

        if ($line === null) {
            return;
        }

        $amount = min($due, (int) $line->cash_minor);

        $this->issues->raise('refund_due', "refund-due:{$event->id}",
            "Refund {$event->event}: ".Money::of($amount, $order->currency)->format()." due to the learner on order {$order->uuid}",
            ['severity' => 'warning', 'subject_type' => 'order_line', 'subject_id' => $line->id, 'amount_minor' => $amount,
                'currency' => $order->currency, 'details' => ['event' => $event->event, 'booking_event_id' => $event->id,
                    'refund_due_percent' => $event->context['refund_due_percent'] ?? null]]);
    }
}
