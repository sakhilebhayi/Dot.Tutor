<?php

namespace App\Domain\Safety\Support;

use App\Domain\Safety\Contracts\AgeGatedSpace;
use App\Domain\Safety\Contracts\RoomDirectory;

/** Honest default until community rooms exist (wave 2): there are no rooms. */
class NullRoomDirectory implements RoomDirectory
{
    public function find(int $roomId): ?AgeGatedSpace
    {
        return null;
    }
}
