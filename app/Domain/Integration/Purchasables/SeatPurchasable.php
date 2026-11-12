<?php

namespace App\Domain\Integration\Purchasables;

use App\Domain\Marketplace\Enums\SeatStatus;
use App\Domain\Marketplace\Models\TutorSessionParticipant;
use App\Domain\Money\Contracts\Purchasable;
use App\Domain\Money\Money;
use App\Enums\SessionStatus;
use App\Models\User;
use Carbon\CarbonInterface;

/** A seat in a tutor's group lesson. Payable while the seat waits for payment within its hold. */
class SeatPurchasable implements Purchasable
{
    public function __construct(public readonly TutorSessionParticipant $seat) {}

    public function purchasableType(): string
    {
        return 'seat';
    }

    public function purchasableId(): int
    {
        return (int) $this->seat->id;
    }

    public function purchaseTitle(): string
    {
        $session = $this->seat->session;

        return mb_substr(sprintf('Group lesson seat: %s with %s, %s', $session?->subject?->name ?? 'Lesson',
            $session?->tutorProfile?->displayName() ?? 'a tutor', $session?->starts_at?->copy()->timezone((string) config('tutor.timezone', 'Africa/Johannesburg'))->format('j M Y H:i T') ?? ''), 0, 200);
    }

    public function purchasePrice(User $buyer): Money
    {
        return Money::of((int) $this->seat->price_minor, strtoupper((string) ($this->seat->currency ?: Money::defaultCurrency())));
    }

    public function sellerId(): ?int
    {
        return $this->seat->session?->tutorProfile?->user_id;
    }

    public function commissionCategory(): string
    {
        return 'lesson';
    }

    public function earningRelease(): string
    {
        return self::RELEASE_ON_DELIVERY;
    }

    public function purchaseRefusal(User $buyer): ?string
    {
        $hold = $this->seat->hold_expires_at;

        return match (true) {
            $this->seat->learner_id !== $buyer->id => 'Only the learner on this seat can pay for it.',
            $this->seat->payment_status === 'paid' => 'This seat is already paid.',
            $this->seat->status !== SeatStatus::AwaitingPayment->value => 'This seat is not waiting for payment.',
            $hold !== null && $hold->isPast() => 'The hold on this seat has run out. Take a seat again.',
            (int) $this->seat->price_minor <= 0 => 'This seat has nothing to pay.',
            $this->sellerId() === null => 'This lesson has no tutor to pay.',
            default => null,
        };
    }

    public function deliveryState(): string
    {
        $session = $this->seat->session;

        return match (true) {
            $this->seat->status === SeatStatus::Attended->value => self::DELIVERED,
            $this->seat->status === SeatStatus::Cancelled->value, $session?->status === SessionStatus::Cancelled->value => self::DELIVERY_CANCELLED,
            $this->seat->status === SeatStatus::NoShow->value, $session?->status === SessionStatus::Disputed->value => self::DELIVERY_NEEDS_REVIEW,
            default => self::DELIVERY_PENDING,
        };
    }

    public function deliveredAt(): ?CarbonInterface
    {
        return $this->seat->status === SeatStatus::Attended->value ? ($this->seat->session?->ended_at ?? $this->seat->updated_at) : null;
    }

    public function isRepeatable(): bool
    {
        return false;
    }
}
