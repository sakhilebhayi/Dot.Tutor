<?php

namespace App\Domain\Safety\Contracts;

/**
 * Lets a room conversation find its room. Rooms arrive with the community agent (wave 2); the default binding
 * knows no rooms, so room conversations cannot be opened until a real directory is bound.
 */
interface RoomDirectory
{
    public function find(int $roomId): ?AgeGatedSpace;
}
