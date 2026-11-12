<?php

namespace App\Domain\Money\Purchasables;

use App\Domain\Money\Contracts\Purchasable;
use App\Domain\Money\Models\Bundle;
use App\Domain\Money\Money;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * A tutor's bundle of their own digital items at one price. Buying it grants access to every item; the tutor
 * earns when the refund window ends.
 */
class BundlePurchasable implements Purchasable
{
    public function __construct(public readonly Bundle $bundle) {}

    public function purchasableType(): string
    {
        return 'bundle';
    }

    public function purchasableId(): int
    {
        return $this->bundle->id;
    }

    public function purchaseTitle(): string
    {
        return mb_substr('Bundle: '.$this->bundle->title, 0, 200);
    }

    public function purchasePrice(User $buyer): Money
    {
        return Money::of($this->bundle->price_minor, $this->bundle->currency);
    }

    public function sellerId(): ?int
    {
        return $this->bundle->seller_id;
    }

    public function commissionCategory(): string
    {
        return 'bundle';
    }

    public function earningRelease(): string
    {
        return self::RELEASE_AFTER_WINDOW;
    }

    public function purchaseRefusal(User $buyer): ?string
    {
        return match (true) {
            ! $this->bundle->is_active => 'This bundle is not on sale.',
            $this->bundle->seller_id === $buyer->id => 'You cannot buy your own bundle.',
            ! $this->bundle->items()->exists() => 'This bundle is empty.',
            default => null,
        };
    }

    public function deliveryState(): string
    {
        return self::DELIVERED;
    }

    public function deliveredAt(): ?CarbonInterface
    {
        return null;
    }

    public function isRepeatable(): bool
    {
        return false;
    }
}
