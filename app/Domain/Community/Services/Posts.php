<?php

namespace App\Domain\Community\Services;

use App\Domain\Academic\Contracts\LanguageProfile;
use App\Domain\Community\Events\AnswerPosted;
use App\Domain\Community\Events\QuestionResolved;
use App\Domain\Community\Models\Post;
use App\Domain\Community\Models\PostReport;
use App\Domain\Community\Models\PostRevision;
use App\Domain\Community\Models\Room;
use App\Domain\Community\Models\StudyGroup;
use App\Domain\Community\Support\CommunityRefusal;
use App\Domain\Learning\Models\LibraryResource;
use App\Domain\Learning\Services\LibraryService;
use App\Domain\Safety\Audit;
use App\Domain\Safety\Contracts\ReportSink;
use App\Domain\Safety\Contracts\SafeguardingGate;
use App\Domain\Safety\Enums\ReportCategory;
use App\Domain\Safety\Services\ContactRedactor;
use App\Domain\Safety\Support\SafeguardingDenied;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Questions, answers, discussions, explanations, revision techniques and shared material. Every post passes the
 * room's access rule, safety's publish check (children's posts are pre-moderated), contact-detail redaction where a
 * minor is involved, and the posting rate limits. Edits keep the earlier text. Reports go to safety's queue.
 */
class Posts
{
    public function __construct(
        private readonly RoomAccess $access,
        private readonly Rooms $rooms,
        private readonly SafeguardingGate $gate,
        private readonly ContactRedactor $redactor,
        private readonly ReportSink $reports,
    ) {}

    /** @param array{title?: ?string, body: string, original_locale?: ?string, library_resource_id?: ?int} $data */
    public function create(User $author, Room $room, string $kind, array $data, ?Post $parent = null, ?StudyGroup $group = null): Post
    {
        if (! $this->access->canParticipate($author, $room)) {
            $decision = $this->access->eligibility($author, $room);
            if ($decision->denied()) {
                throw new SafeguardingDenied($decision);
            }
            throw CommunityRefusal::because('not_a_member', 'Join this room before posting in it.', 403);
        }

        $this->checkKind($kind, $parent, $room);
        $this->throttle($author, 'post', (int) config('community.posts_per_minute'), 60);
        $this->throttle($author, 'post-day', (int) config('community.posts_per_day'), 86400);

        $publish = $this->gate->allows($author, 'publish', $room, ['audience' => 'room']);
        if ($publish->denied()) {
            throw new SafeguardingDenied($publish);
        }

        $resourceId = null;
        if ($kind === Post::MATERIAL) {
            $resourceId = $this->checkResource($author, (int) ($data['library_resource_id'] ?? 0))->id;
        }

        $text = $this->prepareText($author, $room, $data['title'] ?? null, $data['body']);

        $post = DB::transaction(function () use ($author, $room, $kind, $data, $parent, $group, $publish, $resourceId, $text) {
            if (! $room->isTutorRoom() && $this->access->activeMembership($author, $room) === null && ! $this->access->isOperator($author)) {
                $this->rooms->joinSubjectRoom($author, $room);
            }

            $post = Post::create([
                'room_id' => $room->id,
                'group_id' => $group?->id ?? $parent?->group_id,
                'parent_id' => $parent?->id,
                'author_id' => $author->id,
                'kind' => $kind,
                'title' => in_array($kind, Post::THREAD_KINDS, true) ? $text['title'] : null,
                'body' => $text['body'],
                'original_text' => $text['original'],
                'redactions' => $text['redactions'],
                'original_locale' => $this->locale($data['original_locale'] ?? null, $author),
                'library_resource_id' => $resourceId,
                'status' => $publish->requires('moderation:pre') ? Post::HELD : Post::VISIBLE,
                'held_reason' => $publish->requires('moderation:pre') ? 'pre_moderation' : null,
                'last_activity_at' => now(),
            ]);

            if ($parent !== null && $post->isVisible()) {
                $parent->forceFill(['last_activity_at' => now()])->save();
            }

            return $post;
        });

        // Only an answer others can see is announced (a held one is announced when a moderator releases it).
        if ($post->kind === Post::ANSWER && $post->isVisible()) {
            AnswerPosted::dispatch($post->id);
        }

        return $post;
    }

    /** @param array{title?: ?string, body: string} $data */
    public function edit(User $editor, Post $post, array $data): Post
    {
        if ($post->author_id !== $editor->id) {
            throw CommunityRefusal::because('not_author', 'Only the person who wrote this can edit it.', 403);
        }
        if (! in_array($post->status, [Post::VISIBLE, Post::HELD], true)) {
            throw CommunityRefusal::because('not_editable', 'This post was removed and cannot be edited.', 409);
        }

        $room = $post->room;
        if (! $this->access->canParticipate($editor, $room)) {
            throw CommunityRefusal::because('not_a_member', 'You can no longer post in this room.', 403);
        }
        $this->throttle($editor, 'edit', (int) config('community.edits_per_minute'), 60);

        $publish = $this->gate->allows($editor, 'publish', $room, ['audience' => 'room']);
        if ($publish->denied()) {
            throw new SafeguardingDenied($publish);
        }

        $text = $this->prepareText($editor, $room, $data['title'] ?? $post->title, $data['body']);

        return DB::transaction(function () use ($editor, $post, $text, $publish) {
            PostRevision::create(['post_id' => $post->id, 'editor_id' => $editor->id, 'title' => $post->title, 'body' => $post->body]);

            $held = $publish->requires('moderation:pre') || $post->status === Post::HELD;
            $post->forceFill([
                'title' => $post->isThread() ? $text['title'] : null,
                'body' => $text['body'],
                'original_text' => $text['original'] ?? $post->original_text,
                'redactions' => max($post->redactions, $text['redactions']),
                'edited_at' => now(),
                'status' => $held ? Post::HELD : Post::VISIBLE,
                'held_reason' => $held ? ($post->held_reason ?? 'pre_moderation') : null,
            ])->save();

            return $post->fresh();
        });
    }

    /** Accept an answer: the asker, or a tutor in the room. The question becomes resolved. */
    public function accept(User $actor, Post $answer): Post
    {
        $question = $answer->parent;
        if ($question === null || $question->kind !== Post::QUESTION || $answer->kind !== Post::ANSWER) {
            throw CommunityRefusal::because('not_an_answer', 'Only an answer to a question can be accepted.', 422);
        }
        $this->mayResolve($actor, $question);
        if (! $answer->isVisible()) {
            throw CommunityRefusal::because('answer_not_visible', 'This answer is waiting for a moderator and cannot be accepted yet.', 409);
        }

        $question->forceFill([
            'accepted_answer_id' => $answer->id,
            'resolved_at' => $question->resolved_at ?? now(),
            'resolved_by_id' => $actor->id,
            'last_activity_at' => now(),
        ])->save();
        QuestionResolved::dispatch($question->id, $actor->id, $answer->id);

        return $question->fresh();
    }

    public function resolve(User $actor, Post $question, bool $resolved): Post
    {
        if ($question->kind !== Post::QUESTION) {
            throw CommunityRefusal::because('not_a_question', 'Only questions are resolved.', 422);
        }
        $this->mayResolve($actor, $question);

        $question->forceFill($resolved
            ? ['resolved_at' => $question->resolved_at ?? now(), 'resolved_by_id' => $actor->id, 'last_activity_at' => now()]
            : ['resolved_at' => null, 'resolved_by_id' => null, 'accepted_answer_id' => null, 'last_activity_at' => now()])->save();
        if ($resolved) {
            QuestionResolved::dispatch($question->id, $actor->id, $question->accepted_answer_id);
        }

        return $question->fresh();
    }

    /** The author withdraws their own post; operators remove anyone's. The row stays (reports may point at it). */
    public function remove(User $actor, Post $post): void
    {
        $isAuthor = $post->author_id === $actor->id;
        if (! $isAuthor && ! $this->access->isOperator($actor)) {
            throw CommunityRefusal::because('not_author', 'Only the person who wrote this can remove it.', 403);
        }

        $post->forceFill(['status' => Post::REMOVED])->save();

        if ($post->parent?->accepted_answer_id === $post->id) {
            $post->parent->forceFill(['accepted_answer_id' => null])->save();
        }

        Audit::record($actor, $isAuthor ? 'community.post.withdrawn' : 'community.post.removed', $post);
    }

    /**
     * Report a post to safety's moderation queue. A child-safety report holds the post at once; otherwise it is
     * held when enough different people have reported it.
     */
    public function report(User $reporter, Post $post, ReportCategory $category, ?string $details): PostReport
    {
        if (! $this->canSee($reporter, $post)) {
            abort(404);
        }

        $report = $this->reports->report($reporter, $post, $category, $details);

        return DB::transaction(function () use ($reporter, $post, $category, $report) {
            $row = PostReport::firstOrCreate(
                ['post_id' => $post->id, 'reporter_id' => $reporter->id],
                ['safety_report_id' => $report->id, 'category' => $category->value],
            );

            $distinct = PostReport::query()->where('post_id', $post->id)->count();
            $fresh = Post::query()->lockForUpdate()->find($post->id);
            $fresh->forceFill(['reporters_count' => $distinct]);

            if ($fresh->status === Post::VISIBLE && ($category->isChildSafety() || $distinct >= (int) config('community.hold_after_reports'))) {
                $fresh->forceFill(['status' => Post::HELD, 'held_reason' => $category->isChildSafety() ? 'reported_child_safety' : 'reported']);
                Audit::record(null, 'community.post.held_after_reports', $fresh, ['reporters' => $distinct, 'category' => $category->value]);
            }

            $fresh->save();

            return $row;
        });
    }

    /** An operator releases a held post or removes it. */
    public function moderate(User $operator, Post $post, string $decision, ?string $note = null): Post
    {
        if (! $this->access->isOperator($operator)) {
            abort(403);
        }

        $heldReason = $post->held_reason;

        match ($decision) {
            'release' => $post->moderationRestore($operator),
            'remove' => $post->moderationHide($operator),
            default => throw CommunityRefusal::because('unknown_decision', 'Choose release or remove.', 422),
        };

        Audit::record($operator, 'community.post.'.($decision === 'release' ? 'released' : 'removed_by_moderator'), $post, [
            'held_reason' => $heldReason,
            'note_given' => filled($note),
        ]);

        if ($decision === 'release' && $post->kind === Post::ANSWER && $post->isVisible()) {
            AnswerPosted::dispatch($post->id);
        }

        return $post->fresh();
    }

    /** Can this person see this post (room access, and the post visible or their own held post)? */
    public function canSee(User $user, Post $post): bool
    {
        if (! $this->access->canRead($user, $post->room)) {
            return false;
        }

        if ($post->isVisible() || $this->access->isOperator($user)) {
            return true;
        }

        return $post->status === Post::HELD && $post->author_id === $user->id;
    }

    private function mayResolve(User $actor, Post $question): void
    {
        $room = $question->room;
        $allowed = $question->author_id === $actor->id || $this->access->isTutorIn($actor, $room);
        if (! $allowed || ! $this->access->canRead($actor, $room)) {
            throw CommunityRefusal::because('not_allowed', 'Only the person who asked, or a tutor in this room, can do that.', 403);
        }
    }

    private function checkKind(string $kind, ?Post $parent, Room $room): void
    {
        $valid = match ($kind) {
            Post::ANSWER => $parent?->kind === Post::QUESTION,
            Post::REPLY => $parent !== null && $parent->isThread() && $parent->kind !== Post::QUESTION,
            default => in_array($kind, Post::THREAD_KINDS, true) && $parent === null,
        };

        if (! $valid) {
            throw CommunityRefusal::because('invalid_kind', 'Answers go under questions and replies under discussions.', 422);
        }

        if ($parent !== null && ($parent->room_id !== $room->id || ! $parent->isVisible())) {
            throw CommunityRefusal::because('parent_not_available', 'That post is not available to reply to.', 404);
        }
    }

    private function checkResource(User $author, int $resourceId): LibraryResource
    {
        $resource = LibraryResource::query()->find($resourceId);
        if ($resource === null || ! app(LibraryService::class)->canSee($author, $resource) || $resource->status->value !== 'published') {
            throw CommunityRefusal::because('resource_not_available', 'Share a published item from the library. Upload new material to the library first.', 422);
        }

        return $resource;
    }

    /**
     * Hide contact details when the room is for learners under 18 or the author is a minor; keep the original
     * text encrypted for moderators.
     *
     * @return array{title: ?string, body: string, original: ?string, redactions: int}
     */
    private function prepareText(User $author, Room $room, ?string $title, string $body): array
    {
        $title = $title !== null ? trim($title) : null;
        $body = trim($body);

        if (! $room->isForMinors() && $this->access->audienceFor($author) === Room::ADULT) {
            return ['title' => $title, 'body' => $body, 'original' => null, 'redactions' => 0];
        }

        $redactedTitle = $title !== null ? $this->redactor->redact($title) : ['text' => null, 'redactions' => []];
        $redactedBody = $this->redactor->redact($body);
        $count = array_sum($redactedTitle['redactions']) + array_sum($redactedBody['redactions']);

        return [
            'title' => $redactedTitle['text'],
            'body' => $redactedBody['text'],
            'original' => $count > 0 ? json_encode(['title' => $title, 'body' => $body], JSON_UNESCAPED_UNICODE) : null,
            'redactions' => $count,
        ];
    }

    private function locale(?string $requested, User $author): string
    {
        if ($requested !== null && preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', $requested)) {
            return $requested;
        }

        return (string) (app(LanguageProfile::class)->interfaceLocale($author) ?: config('app.locale', 'en'));
    }

    private function throttle(User $user, string $what, int $max, int $decay): void
    {
        $key = "community-{$what}:{$user->id}";
        if ($max > 0 && RateLimiter::tooManyAttempts($key, $max)) {
            throw CommunityRefusal::because('too_many', 'You are posting very quickly. Wait a little and try again.', 429,
                ['retry_after' => RateLimiter::availableIn($key)]);
        }
        RateLimiter::hit($key, $decay);
    }
}
