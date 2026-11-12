<?php

namespace App\Domain\Community\Listeners;

use App\Domain\Community\Models\Room;
use App\Domain\Community\Models\RoomMember;
use App\Domain\Community\Services\RoomAccess;
use App\Domain\Money\Events\OrderPaid;
use App\Domain\Money\Models\Order;

/** When money confirms an order, any room places in it become active (the read path re-checks as a backstop). */
class ActivatePaidRoomPlaces
{
    public function __construct(private readonly RoomAccess $access) {}

    public function handle(OrderPaid $event): void
    {
        $order = Order::query()->with('lines')->find($event->orderId);
        if ($order === null) {
            return;
        }

        foreach ($order->lines->where('purchasable_type', 'room') as $line) {
            $room = Room::query()->find($line->purchasable_id);
            $member = $room ? RoomMember::query()->where('room_id', $room->id)->where('user_id', $order->buyer_id)->first() : null;
            if ($room && $member) {
                $this->access->activateIfPaid($member, $room);
            }
        }
    }
}
