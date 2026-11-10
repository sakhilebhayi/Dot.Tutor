<?php

namespace App\Domain\Money\Contracts;

use App\Domain\Money\Money;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Anything that can be bought: a booking, an offering or package, a course, a resource, room access, a
 * bundle. The owning domain implements it (or an adapter does) and registers a resolver with
 * App\Domain\Money\Purchasables. Money never trusts a price from the client: it asks the purchasable.
 */
interface Purchasable
{
    /** Earnings become available once the item is delivered (a lesson completed). */
    public const RELEASE_ON_DELIVERY = 'on_delivery';

    /** Earnings become available when the refund window after purchase ends (courses, resources). */
    public const RELEASE_AFTER_WINDOW = 'after_window';

    public const DELIVERY_PENDING = 'pending';

    public const DELIVERED = 'delivered';

    public const DELIVERY_CANCELLED = 'cancelled';

    /** Something happened that a person must look at (a no-show, a dispute) before money moves. */
    public const DELIVERY_NEEDS_REVIEW = 'needs_review';

    /** Short type key: booking, offering, package, course, resource, room, bundle. */
    public function purchasableType(): string;

    public function purchasableId(): int;

    public function purchaseTitle(): string;

    /** The price this buyer pays before coupons and credit (server side, never from the client). */
    public function purchasePrice(User $buyer): Money;

    /** The tutor who sells it (user id); null when the platform sells it. */
    public function sellerId(): ?int;

    /** Category key for commission overrides (e.g. lesson, course, resource, bundle). */
    public function commissionCategory(): string;

    /** One of the RELEASE_* constants. */
    public function earningRelease(): string;

    /** Why this buyer may not buy it now, or null when they may. */
    public function purchaseRefusal(User $buyer): ?string;

    /** One of the DELIVERY_* constants. */
    public function deliveryState(): string;

    public function deliveredAt(): ?CarbonInterface;

    /** Can the same buyer buy it again while a purchase is active (packages, rooms by the month)? */
    public function isRepeatable(): bool;
}
