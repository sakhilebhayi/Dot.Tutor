<?php

namespace App\Domain\Community\Support;

use App\Domain\Community\Models\Room;
use App\Domain\Safety\Contracts\AgeGatedSpace;
use App\Domain\Safety\Contracts\RoomDirectory;

/** Safety's RoomDirectory seam, answered from community rooms (room conversations, room age bands). */
class EloquentRoomDirectory implements RoomDirectory
{
    public function find(int $roomId): ?AgeGatedSpace
    {
        return Room::query()->where('status', 'active')->find($roomId);
    }
}
