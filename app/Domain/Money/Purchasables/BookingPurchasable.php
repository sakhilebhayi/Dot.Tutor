<?php

namespace App\Domain\Money\Purchasables;

use App\Domain\Money\Contracts\Purchasable;
use App\Domain\Money\Money;
use App\Enums\SessionStatus;
use App\Models\TutorSession;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * A booked lesson (the existing tutor_sessions row) as something a learner pays for. The price is the rate
 * snapshotted at booking times the duration, in minor units (rate_minor when the marketplace domain adds it,
 * else the decimal rate converted by string). The tutor earns once the lesson is completed.
 */
class BookingPurchasable implements Purchasable
{
    public function __construct(public readonly TutorSession $session) {}

    public function purchasableType(): string
    {
        return 'booking';
    }

    public function purchasableId(): int
    {
        return $this->session->id;
    }

    public function purchaseTitle(): string
    {
        $subject = $this->session->subject?->name ?? 'Lesson';
        $tutor = $this->session->tutorProfile?->user?->name;

        return mb_substr(sprintf('%s lesson%s, %s, %d min', $subject, $tutor ? ' with '.$tutor : '', $this->session->starts_at->format('j M Y H:i'), $this->session->duration_minutes), 0, 200);
    }

    public function purchasePrice(User $buyer): Money
    {
        $currency = strtoupper((string) ($this->session->currency ?: Money::defaultCurrency()));
        $rateMinor = $this->session->getAttribute('rate_minor');
        $hourly = is_numeric($rateMinor) ? Money::of((int) $rateMinor, $currency) : Money::fromDecimal((string) $this->session->getRawOriginal('rate'), $currency);

        return $hourly->ratio((int) $this->session->duration_minutes, 60);
    }

    public function sellerId(): ?int
    {
        return $this->session->tutorProfile?->user_id;
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
        return match (true) {
            $this->session->student_id !== $buyer->id => 'Only the learner who booked this lesson can pay for it.',
            ! in_array($this->session->status, [SessionStatus::Pending->value, SessionStatus::Confirmed->value], true) => 'This lesson can no longer be paid for.',
            $this->sellerId() === null => 'This lesson has no tutor to pay.',
            $this->sellerId() === $buyer->id => 'You cannot pay yourself.',
            default => null,
        };
    }

    public function deliveryState(): string
    {
        return match ($this->session->status) {
            SessionStatus::Completed->value => self::DELIVERED,
            SessionStatus::Cancelled->value => self::DELIVERY_CANCELLED,
            SessionStatus::NoShow->value => self::DELIVERY_NEEDS_REVIEW,
            default => self::DELIVERY_PENDING,
        };
    }

    public function deliveredAt(): ?CarbonInterface
    {
        return $this->session->status === SessionStatus::Completed->value ? $this->session->updated_at : null;
    }

    public function isRepeatable(): bool
    {
        return false;
    }
}
