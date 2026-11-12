<?php

namespace App\Domain\Integration\Purchasables;

use App\Domain\Marketplace\Models\TutorPackagePurchase;
use App\Domain\Money\Contracts\Purchasable;
use App\Domain\Money\Money;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * A prepaid package of lessons. The tutor's earning waits until every lesson is used (delivered); a package that
 * expires with lessons left, or is cancelled, goes to finance (needs review / cancelled) rather than paying out
 * automatically.
 */
class PackagePurchasable implements Purchasable
{
    public function __construct(public readonly TutorPackagePurchase $purchase) {}

    public function purchasableType(): string
    {
        return 'package';
    }

    public function purchasableId(): int
    {
        return (int) $this->purchase->id;
    }

    public function purchaseTitle(): string
    {
        return mb_substr($this->purchase->lessons_total.'-lesson package with '.($this->purchase->tutorProfile?->displayName() ?? 'a tutor'), 0, 200);
    }

    public function purchasePrice(User $buyer): Money
    {
        return Money::of((int) $this->purchase->price_minor, strtoupper((string) ($this->purchase->currency ?: Money::defaultCurrency())));
    }

    public function sellerId(): ?int
    {
        return $this->purchase->tutorProfile?->user_id;
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
            $this->purchase->learner_id !== $buyer->id => 'Only the learner who bought this package can pay for it.',
            $this->purchase->payment_status === 'paid' => 'This package is already paid.',
            $this->purchase->status !== TutorPackagePurchase::AWAITING_PAYMENT => 'This package is not waiting for payment.',
            (int) $this->purchase->price_minor <= 0 => 'This package has nothing to pay.',
            $this->sellerId() === null => 'This package has no tutor to pay.',
            default => null,
        };
    }

    public function deliveryState(): string
    {
        return match ($this->purchase->status) {
            TutorPackagePurchase::EXHAUSTED => self::DELIVERED,
            TutorPackagePurchase::CANCELLED => self::DELIVERY_CANCELLED,
            TutorPackagePurchase::EXPIRED => self::DELIVERY_NEEDS_REVIEW,
            default => self::DELIVERY_PENDING,
        };
    }

    public function deliveredAt(): ?CarbonInterface
    {
        return $this->purchase->status === TutorPackagePurchase::EXHAUSTED ? $this->purchase->updated_at : null;
    }

    public function isRepeatable(): bool
    {
        return false;
    }
}
