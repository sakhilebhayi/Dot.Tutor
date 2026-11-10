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

    /** @return array{version: string, room_id: int, open_questions: int, resolved_questions: int, latest_post_id: ?int, last_activity_at: ?string} */
    public function summary(Room $room, User $viewer): array
    {
        $posts = Post::query()->where('room_id', $room->id)->where('status', Post::VISIBLE);
        $latest = (clone $posts)->max('id');
        $stamp = (clone $posts)->max('updated_at');
        $count = (clone $posts)->count();
        $groups = StudyGroup::query()->where('room_id', $room->id)->max('updated_at');
        $sessions = StudySession::query()->where('room_id', $room->id)->max('updated_at');
        $counts = $this->rooms->questionCounts([$room->id])[$room->id] ?? ['open' => 0, 'resolved' => 0, 'last' => null];

        return [
            'version' => substr(sha1(implode('|', [$room->id, $latest, $stamp, $count, $groups, $sessions, $room->updated_at])), 0, 20),
            'room_id' => $room->id,
            'open_questions' => $counts['open'],
            'resolved_questions' => $counts['resolved'],
            'latest_post_id' => $latest !== null ? (int) $latest : null,
            'last_activity_at' => $counts['last'] ? (string) $counts['last'] : null,
        ];
    }
}
