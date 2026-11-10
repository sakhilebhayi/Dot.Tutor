<?php

namespace App\Domain\Integration\Purchasables;

use App\Domain\Money\Money;
use App\Domain\Money\Purchasables\BookingPurchasable;
use App\Enums\SessionStatus;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * A lesson booked through marketplace booking v2 (it has an offering): the price is the snapshot taken at booking
 * time (price_minor), and it can be paid only while the tutor has accepted it and it waits for payment, within the
 * payment hold. Lessons booked the pre-R-47 way (no offering) keep money's own rule (rate x duration, pending or
 * confirmed).
 */
class MarketplaceBookingPurchasable extends BookingPurchasable
{
    public function purchasePrice(User $buyer): Money
    {
        if (! $this->isV2()) {
            return parent::purchasePrice($buyer);
        }

        return Money::of((int) $this->session->price_minor, strtoupper((string) ($this->session->currency ?: Money::defaultCurrency())));
    }

    public function purchaseRefusal(User $buyer): ?string
    {
        if (! $this->isV2()) {
            return parent::purchaseRefusal($buyer);
        }

        $hold = $this->session->hold_expires_at;

        return match (true) {
            $this->session->student_id !== $buyer->id => 'Only the learner who booked this lesson can pay for it.',
            $this->session->payment_status === 'paid' => 'This lesson is already paid.',
            $this->session->status !== SessionStatus::AwaitingPayment->value => 'This lesson is not waiting for payment. The tutor accepts a request first.',
            $hold !== null && $hold->isPast() => 'The time to pay for this lesson has run out. Request it again.',
            (int) $this->session->price_minor <= 0 => 'This lesson has nothing to pay.',
            $this->sellerId() === null => 'This lesson has no tutor to pay.',
            default => null,
        };
    }

    public function deliveryState(): string
    {
        return match ($this->session->status) {
            SessionStatus::Completed->value => self::DELIVERED,
            SessionStatus::Cancelled->value => self::DELIVERY_CANCELLED,
            SessionStatus::NoShow->value, SessionStatus::Disputed->value => self::DELIVERY_NEEDS_REVIEW,
            default => self::DELIVERY_PENDING,
        };
    }

    public function deliveredAt(): ?CarbonInterface
    {
        if ($this->session->status !== SessionStatus::Completed->value) {
            return null;
        }

        return $this->session->ended_at ?? $this->session->updated_at;
    }

    private function isV2(): bool
    {
        return $this->session->tutor_offering_id !== null;
    }
}
