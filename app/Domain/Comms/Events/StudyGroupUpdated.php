<?php

namespace App\Domain\Comms\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * The event the community domain (wave 2) dispatches when something in a study group needs its members' attention:
 * a new session, a changed time, an answer to their question. Comms turns it into one notification per member,
 * collapsed per group, under the "Study groups" category. Dispatch after commit.
 *
 * kind: 'session_scheduled' | 'session_changed' | 'answer' | 'announcement' | 'member_joined'
 * memberIds: who to tell (comms leaves out the actor). url: a path on this site.
 */
final class StudyGroupUpdated
{
    use Dispatchable;

    /** @param list<int> $memberIds */
    public function __construct(
        public int $groupId,
        public string $groupName,
        public string $kind,
        public string $summary,
        public array $memberIds,
        public ?int $actorId = null,
        public ?string $url = null,
        public ?string $occurrenceKey = null,
    ) {}
}
