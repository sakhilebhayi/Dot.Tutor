<?php

namespace App\Domain\Community\Support;

use App\Domain\Community\Models\Room;
use App\Domain\Community\Models\RoomMember;
use App\Domain\Money\Contracts\Purchasable;
use App\Domain\Money\Money;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * A paid tutor room as a money Purchasable (type "room"). Money asks it for the price and whether this buyer may
 * buy it; it never trusts a price from the client. Access is granted from money's entitlement after payment.
 */
class RoomPurchasable implements Purchasable
{
    public function __construct(public readonly Room $room) {}

    public function purchasableType(): string
    {
        return 'room';
    }

    public function purchasableId(): int
    {
        return $this->room->id;
    }

    public function purchaseTitle(): string
    {
        return mb_substr('Room: '.$this->room->displayName(), 0, 200);
    }

    public function purchasePrice(User $buyer): Money
    {
        return Money::of($this->room->price_minor, $this->room->currency);
    }

    public function sellerId(): ?int
    {
        return $this->room->owner_id;
    }

    public function commissionCategory(): string
    {
        return 'room';
    }

    public function earningRelease(): string
    {
        return self::RELEASE_AFTER_WINDOW;
    }

    public function purchaseRefusal(User $buyer): ?string
    {
        $room = $this->room->fresh() ?? $this->room;
        $member = RoomMember::query()->where('room_id', $room->id)->where('user_id', $buyer->id)->first();

        return match (true) {
            ! $room->isTutorRoom() || ! $room->isPaid() => 'This room is free; join it from the room page.',
            ! $room->isActive() => 'This room is closed.',
            $room->owner_id === $buyer->id => 'You cannot buy a place in your own room.',
            $member?->status === RoomMember::REMOVED => 'The tutor removed you from this room.',
            $room->capacity !== null && $room->seats_taken >= $room->capacity => 'This room is full.',
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
