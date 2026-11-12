<?php

namespace App\Domain\Community\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Someone asked to join a tutor room or a study group whose join rule is approval. The comms domain listens (tells
 * the room's tutor or the group's organiser).
 *
 * scope: 'room' (membershipId is a community_room_members row) | 'group' (a community_group_members row)
 */
final class JoinRequested implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public const ROOM = 'room';

    public const GROUP = 'group';

    public function __construct(
        public readonly string $scope,
        public readonly int $targetId,
        public readonly int $requesterId,
        public readonly int $membershipId,
    ) {}
}
