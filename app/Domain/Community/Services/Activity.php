<?php

namespace App\Domain\Community\Services;

use App\Domain\Community\Models\Post;
use App\Domain\Community\Models\Room;
use App\Domain\Community\Models\StudyGroup;
use App\Domain\Community\Models\StudySession;
use App\Models\User;

/**
 * A short, versioned summary of a room for polling (no websockets on the shared host). The version changes when a
 * visible post, a resolution, a group or a session changes; screens send it back as If-None-Match and get 304.
 */
class Activity
{
    public function __construct(private readonly Rooms $rooms) {}

    /**
     * The room's version alone (R-47 D-1608): one aggregate over the room's visible posts and two over its groups
     * and sessions. A poll whose ETag still matches is answered 304 from this without counting questions.
     */
    public function version(Room $room): string
    {
        return $this->versionFrom($room, $this->postAggregates($room));
    }

    /** @return array{version: string, room_id: int, open_questions: int, resolved_questions: int, latest_post_id: ?int, last_activity_at: ?string} */
    public function summary(Room $room, User $viewer): array
    {
        $posts = $this->postAggregates($room);
        $counts = $this->rooms->questionCounts([$room->id])[$room->id] ?? ['open' => 0, 'resolved' => 0, 'last' => null];

        return [
            'version' => $this->versionFrom($room, $posts),
            'room_id' => $room->id,
            'open_questions' => $counts['open'],
            'resolved_questions' => $counts['resolved'],
            'latest_post_id' => $posts->latest !== null ? (int) $posts->latest : null,
            'last_activity_at' => $counts['last'] ? (string) $counts['last'] : null,
        ];
    }

    /** Latest id, latest update and count of the room's visible posts, in one statement (was three). */
    private function postAggregates(Room $room): object
    {
        return Post::query()->where('room_id', $room->id)->where('status', Post::VISIBLE)
            ->toBase()->selectRaw('max(id) as latest, max(updated_at) as stamp, count(*) as n')->first();
    }

    private function versionFrom(Room $room, object $posts): string
    {
        $groups = StudyGroup::query()->where('room_id', $room->id)->max('updated_at');
        $sessions = StudySession::query()->where('room_id', $room->id)->max('updated_at');

        return substr(sha1(implode('|', [$room->id, $posts->latest, $posts->stamp, (int) $posts->n, $groups, $sessions, $room->updated_at])), 0, 20);
    }
}
