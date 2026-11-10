<?php

namespace App\Domain\Integration\Listeners;

use App\Domain\Integration\Support\MoneyTypes;
use App\Domain\Learning\Enums\CourseStatus;
use App\Domain\Learning\Models\Bundle;
use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Services\ProgressService;
use App\Domain\Marketplace\Exceptions\MarketplaceException;
use App\Domain\Marketplace\Services\PaymentService;
use App\Domain\Money\Events\OrderPaid;
use App\Domain\Money\Models\Order;
use App\Domain\Money\Models\OrderLine;
use App\Domain\Money\Services\FinanceIssues;
use App\Enums\SessionStatus;
use App\Models\TutorSession;
use Illuminate\Validation\ValidationException;

/**
 * After money settles an order (Paystack verified, or nothing due), each line is fulfilled in its own domain:
 *  - a marketplace lesson, seat or package: PaymentService::paid (confirms the booking, the seat, or activates the
 *    package; idempotent). A pre-R-47 lesson that is not waiting for payment is only marked paid, so the tutor's
 *    acceptance confirms it without asking for payment again;
 *  - a course: the buyer is enrolled; a course bundle: enrolled in each published course in it.
 * A line that cannot be fulfilled (the booking moved on) is raised to finance as "paid_item_not_fulfilled".
 */
class FulfilPaidOrder
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly ProgressService $progress,
        private readonly FinanceIssues $issues,
    ) {}

    public function handle(OrderPaid $event): void
    {
        $order = Order::query()->with(['lines', 'buyer'])->find($event->orderId);

        if ($order === null || ! $order->isSettled()) {
            return;
        }

        foreach ($order->lines as $line) {
            try {
                $this->fulfil($order, $line);
            } catch (MarketplaceException|ValidationException $e) {
                $this->issues->raise('paid_item_not_fulfilled', "unfulfilled:{$line->id}",
                    "Order {$order->uuid}: a paid {$line->purchasable_type} could not be fulfilled ({$e->getMessage()}); refund or fix it",
                    ['severity' => 'critical', 'subject_type' => 'order_line', 'subject_id' => $line->id,
                        'amount_minor' => (int) $line->cash_minor, 'currency' => $order->currency]);
            }
        }
    }

    private function fulfil(Order $order, OrderLine $line): void
    {
        $id = (int) $line->purchasable_id;

        if ($line->purchasable_type === 'booking') {
            $session = TutorSession::query()->find($id);

            if ($session === null) {
                return;
            }

            if ($session->status === SessionStatus::AwaitingPayment->value) {
                $this->payments->paid('tutor_session', $id, $order->uuid);
            } elseif ($session->payment_status !== 'paid') {
                $session->forceFill(['payment_status' => 'paid', 'order_reference' => $order->uuid])->saveQuietly();
            }

            return;
        }

        if (($type = MoneyTypes::marketplaceType($line->purchasable_type)) !== null) {
            $this->payments->paid($type, $id, $order->uuid);

            return;
        }

        if ($order->buyer === null) {
            return;
        }

        if ($line->purchasable_type === 'course' && ($course = Course::query()->find($id)) !== null) {
            $this->progress->enrol($order->buyer, $course);
        }

        if ($line->purchasable_type === 'course_bundle' && ($bundle = Bundle::query()->find($id)) !== null) {
            foreach ($bundle->courses()->where('status', CourseStatus::Published->value)->get() as $course) {
                $this->progress->enrol($order->buyer, $course);
            }
        }
    }
}
