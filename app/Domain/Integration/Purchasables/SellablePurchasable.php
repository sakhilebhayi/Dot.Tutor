<?php

namespace App\Domain\Integration\Purchasables;

use App\Domain\Integration\Support\MoneyTypes;
use App\Domain\Learning\Contracts\Sellable;
use App\Domain\Learning\Enums\CourseStatus;
use App\Domain\Learning\Enums\ResourceStatus;
use App\Domain\Learning\Models\Bundle;
use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\LibraryResource;
use App\Domain\Money\Contracts\Purchasable;
use App\Domain\Money\Money;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * A learning course, course bundle or library resource as money sees it. Digital items: access is granted at
 * payment, and the creator's earning becomes available when the refund window ends.
 */
class SellablePurchasable implements Purchasable
{
    public function __construct(public readonly Sellable $item) {}

    public function purchasableType(): string
    {
        return MoneyTypes::learningToMoney($this->item->sellableType());
    }

    public function purchasableId(): int
    {
        return $this->item->sellableId();
    }

    public function purchaseTitle(): string
    {
        return mb_substr($this->item->sellableDescription(), 0, 200);
    }

    public function purchasePrice(User $buyer): Money
    {
        return Money::of($this->item->priceMinor(), strtoupper($this->item->currency() ?: Money::defaultCurrency()));
    }

    public function sellerId(): ?int
    {
        return $this->item->sellerId();
    }

    public function commissionCategory(): string
    {
        return match ($this->item->sellableType()) {
            'bundle' => 'bundle',
            'resource' => 'resource',
            default => 'course',
        };
    }

    public function earningRelease(): string
    {
        return self::RELEASE_AFTER_WINDOW;
    }

    public function purchaseRefusal(User $buyer): ?string
    {
        $item = $this->item;

        return match (true) {
            $item instanceof Course && $item->status !== CourseStatus::Published => 'This course is not on sale.',
            $item instanceof Course && $item->isFree() => 'This course is free: enrol instead.',
            $item instanceof LibraryResource && $item->status !== ResourceStatus::Published => 'This resource is not available.',
            $item instanceof LibraryResource && (bool) $item->is_free => 'This resource is free: download it instead.',
            $item instanceof Bundle && $item->status !== 'published' => 'This bundle is not on sale.',
            $item->priceMinor() <= 0 => 'This item has no price.',
            $item->sellerId() !== null && $item->sellerId() === $buyer->id => 'You cannot buy your own item.',
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
