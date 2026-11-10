<?php

namespace Tests\Support;

use App\Domain\Money\Contracts\Purchasable;
use App\Domain\Money\Money;
use App\Domain\Money\Purchasables;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * A stand-in for the learning domain's course (registered as type "course" in tests only), so money's handling
 * of digital items (refund window, bundles, entitlements) is tested before learning lands.
 */
class FakeCourse implements Purchasable
{
    /** @var array<int, self> */
    public static array $courses = [];

    public function __construct(public int $id, public int $sellerId, public int $priceMinor, public string $title = 'Grade 11 Algebra') {}

    public static function register(): void
    {
        self::$courses = [];
        app(Purchasables::class)->register('course', fn (int $id) => self::$courses[$id] ?? null);
    }

    public static function make(int $sellerId, int $priceMinor): self
    {
        $id = count(self::$courses) + 1;

        return self::$courses[$id] = new self($id, $sellerId, $priceMinor, 'Course '.$id);
    }

    public function purchasableType(): string
    {
        return 'course';
    }

    public function purchasableId(): int
    {
        return $this->id;
    }

    public function purchaseTitle(): string
    {
        return $this->title;
    }

    public function purchasePrice(User $buyer): Money
    {
        return Money::of($this->priceMinor, 'ZAR');
    }

    public function sellerId(): ?int
    {
        return $this->sellerId;
    }

    public function commissionCategory(): string
    {
        return 'course';
    }

    public function earningRelease(): string
    {
        return self::RELEASE_AFTER_WINDOW;
    }

    public function purchaseRefusal(User $buyer): ?string
    {
        return $buyer->id === $this->sellerId ? 'You cannot buy your own course.' : null;
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
