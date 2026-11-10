<?php

namespace App\Domain\Community\Http\Concerns;

use App\Domain\Community\Models\Post;
use App\Domain\Community\Models\Room;
use Illuminate\Http\Request;

/** One controller action answers the JSON API (/api/tutor/community) and the learner screens (/rooms). */
trait AnswersBoth
{
    protected function wantsJson(Request $request): bool
    {
        return $request->is('api/*') || $request->expectsJson();
    }

    /** @return array<string, mixed> */
    protected function roomJson(Room $room): array
    {
        $room->loadMissing(['subject', 'level.stage', 'curriculum']);

        return [
            'id' => $room->id,
            'kind' => $room->kind,
            'name' => $room->displayName(),
            'audience' => $room->audience,
            'curriculum' => ['id' => $room->curriculum_id, 'name' => $room->curriculum?->name],
            'level' => ['id' => $room->academic_level_id, 'name' => $room->level?->name, 'stage' => $room->level?->stage?->code],
            'subject' => ['id' => $room->subject_id, 'name' => $room->subject?->name],
            'title' => $room->title,
            'description' => $room->description,
            'language' => $room->language,
            'join_rule' => $room->join_rule,
            'capacity' => $room->capacity,
            'seats_taken' => $room->seats_taken,
            'price_minor' => $room->price_minor,
            'currency' => $room->currency,
            'status' => $room->status,
            'url' => route('rooms.show', $room),
        ];
    }

    /** @return array<string, mixed> */
    protected function postJson(Post $post): array
    {
        return [
            'id' => $post->id,
            'room_id' => $post->room_id,
            'group_id' => $post->group_id,
            'parent_id' => $post->parent_id,
            'kind' => $post->kind,
            'title' => $post->title,
            'body' => $post->body,
            'original_locale' => $post->original_locale,
            'contact_details_hidden' => $post->redactions > 0,
            'library_resource_id' => $post->library_resource_id,
            'status' => $post->status,
            'held' => $post->heldLabel(),
            'author' => $post->author ? ['id' => $post->author->id, 'name' => \App\Domain\Community\Support\Names::short($post->author)] : null,
            'resolved' => $post->isResolved(),
            'accepted_answer_id' => $post->accepted_answer_id,
            'edited_at' => $post->edited_at?->toIso8601String(),
            'created_at' => $post->created_at?->toIso8601String(),
            'url' => route('rooms.posts.show', $post->isThread() ? $post : $post->parent_id).($post->isThread() ? '' : '#post-'.$post->id),
        ];
    }
}
