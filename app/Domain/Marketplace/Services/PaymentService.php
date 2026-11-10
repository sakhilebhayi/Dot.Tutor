<?php

namespace App\Domain\Marketplace\Services;

use App\Domain\Marketplace\Contracts\CheckoutPort;
use App\Domain\Marketplace\Enums\SeatStatus;
use App\Domain\Marketplace\Exceptions\MarketplaceException;
use App\Domain\Marketplace\Models\TutorPackagePurchase;
use App\Domain\Marketplace\Models\TutorSessionParticipant;
use App\Domain\Marketplace\Support\CheckoutResult;
use App\Enums\SessionStatus;
use App\Models\TutorSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The marketplace side of payment. The learner starts a checkout for a
 * booking, seat or package waiting on payment; the money domain verifies the
 * payment server-side and calls paid() (or failed()). Nothing is ever marked
 * paid from the browser. With the null CheckoutPort the answer is
 * "unconfigured" and the item stays waiting for payment.
 *
 * Purchasable descriptor types: 'tutor_session', 'tutor_session_participant',
 * 'tutor_package_purchase'.
 */
class PaymentService
{
    public function __construct(private CheckoutPort $checkout, private BookingStateMachine $machine) {}

    public function start(TutorSession|TutorSessionParticipant|TutorPackagePurchase $item, User $payer, string $idempotencyKey): CheckoutResult
    {
        [$type, $amount, $currency, $sellerUserId, $description] = $this->describe($item, $payer);

        $result = $this->checkout->start($payer, [
            'type' => $type,
            'id' => (int) $item->getKey(),
            'amount_minor' => $amount,
            'currency' => $currency,
            'seller_user_id' => $sellerUserId,
            'description' => $description,
        ], $idempotencyKey);

        if ($result->status === 'paid' && $result->orderReference !== null) {
            $this->paid($type, (int) $item->getKey(), $result->orderReference);
        } elseif (in_array($result->status, ['pending', 'failed'], true)) {
            $item->forceFill(['payment_status' => $result->status, 'order_reference' => $result->orderReference])->save();
        }

        return $result;
    }

    /** Called by the money domain once the payment is verified server-side. Idempotent. */
    public function paid(string $type, int $id, string $orderReference): void
    {
        match ($type) {
            'tutor_session' => $this->sessionPaid($id, $orderReference),
            'tutor_session_participant' => $this->seatPaid($id, $orderReference),
            'tutor_package_purchase' => $this->packagePaid($id, $orderReference),
            default => throw new MarketplaceException('unknown_purchasable', "Unknown purchasable type {$type}.", 422),
        };
    }

    public function failed(string $type, int $id, ?string $orderReference = null): void
    {
        $model = match ($type) {
            'tutor_session' => TutorSession::query()->find($id),
            'tutor_session_participant' => TutorSessionParticipant::query()->find($id),
            'tutor_package_purchase' => TutorPackagePurchase::query()->find($id),
            default => null,
        };
        if ($model !== null && $model->payment_status !== 'paid') {
            $model->forceFill(['payment_status' => 'failed', 'order_reference' => $orderReference ?? $model->order_reference])->save();
        }
    }

    /** @return array{0: string, 1: int, 2: string, 3: ?int, 4: string} */
    private function describe(TutorSession|TutorSessionParticipant|TutorPackagePurchase $item, User $payer): array
    {
        if ($item instanceof TutorSession) {
            if ($item->student_id !== $payer->id) {
                throw new MarketplaceException('forbidden', 'Only the learner who booked can pay for this booking.', 403);
            }
            if ($item->status !== SessionStatus::AwaitingPayment->value) {
                throw new MarketplaceException('not_awaiting_payment', 'This booking is not waiting for payment.', 409);
            }

            return ['tutor_session', (int) $item->price_minor, $item->currency, $item->tutorProfile->user_id, 'Lesson with '.$item->tutorProfile->displayName().' on '.$item->starts_at->toIso8601String()];
        }

        if ($item instanceof TutorSessionParticipant) {
            if ($item->learner_id !== $payer->id) {
                throw new MarketplaceException('forbidden', 'Only the learner on this seat can pay for it.', 403);
            }
            if ($item->status !== SeatStatus::AwaitingPayment->value || ($item->hold_expires_at !== null && $item->hold_expires_at->isPast())) {
                throw new MarketplaceException('not_awaiting_payment', 'This seat is not waiting for payment (the hold may have run out).', 409);
            }
            $session = $item->session;

            return ['tutor_session_participant', (int) $item->price_minor, $item->currency, $session->tutorProfile->user_id, 'Group lesson seat with '.$session->tutorProfile->displayName().' on '.$session->starts_at->toIso8601String()];
        }

        if ($item->learner_id !== $payer->id) {
            throw new MarketplaceException('forbidden', 'Only the learner who bought this package can pay for it.', 403);
        }
        if ($item->status !== TutorPackagePurchase::AWAITING_PAYMENT) {
            throw new MarketplaceException('not_awaiting_payment', 'This package is not waiting for payment.', 409);
        }

        return ['tutor_package_purchase', (int) $item->price_minor, $item->currency, $item->tutorProfile->user_id, $item->lessons_total.'-lesson package with '.$item->tutorProfile->displayName()];
    }

    private function sessionPaid(int $id, string $orderReference): void
    {
        $session = TutorSession::query()->findOrFail($id);
        if ($session->payment_status === 'paid') {
            return;
        }

        $this->machine->transition($session, SessionStatus::Confirmed, null, 'paid', null, [
            'payment_status' => 'paid',
            'order_reference' => $orderReference,
            'hold_expires_at' => null,
        ], ['order_reference' => $orderReference]);
    }

    private function seatPaid(int $id, string $orderReference): void
    {
        DB::transaction(function () use ($id, $orderReference) {
            $seat = TutorSessionParticipant::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($seat->payment_status === 'paid') {
                return;
            }
            if ($seat->status !== SeatStatus::AwaitingPayment->value) {
                // The hold ran out before the money arrived: record it so the money domain refunds.
                $this->machine->record($seat->session, 'payment_after_release', null, $seat->status, $seat->status, 'Payment arrived after the seat was released; refund due.', ['order_reference' => $orderReference, 'refund_due_minor' => (int) $seat->price_minor], $seat->id);

                return;
            }
            $seat->update(['status' => SeatStatus::Confirmed->value, 'payment_status' => 'paid', 'order_reference' => $orderReference, 'hold_expires_at' => null]);
            $this->machine->record($seat->session, 'seat_paid', null, SeatStatus::AwaitingPayment->value, SeatStatus::Confirmed->value, null, ['order_reference' => $orderReference], $seat->id);
        });
    }

    private function packagePaid(int $id, string $orderReference): void
    {
        DB::transaction(function () use ($id, $orderReference) {
            $purchase = TutorPackagePurchase::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($purchase->payment_status === 'paid') {
                return;
            }
            if ($purchase->status !== TutorPackagePurchase::AWAITING_PAYMENT) {
                throw new MarketplaceException('not_awaiting_payment', 'This package is not waiting for payment.', 409);
            }
            $days = $purchase->offering?->package_valid_days;
            $purchase->update([
                'status' => TutorPackagePurchase::ACTIVE,
                'payment_status' => 'paid',
                'order_reference' => $orderReference,
                'expires_at' => $days ? now()->addDays($days) : null,
            ]);
        });
    }
}
