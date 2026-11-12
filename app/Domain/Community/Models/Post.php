<?php

namespace App\Domain\Community\Models;

use App\Domain\Safety\Contracts\Moderatable;
use App\Domain\Safety\Contracts\Reportable;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A room post: a question, discussion, explanation, revision technique or shared material, or an answer or reply
 * under one. The body shown is already redacted when contact details were hidden; the original text is kept
 * encrypted for moderators only.
 */
class Post extends Model implements Moderatable, Reportable
{
    public const QUESTION = 'question';

    public const DISCUSSION = 'discussion';

    public const EXPLANATION = 'explanation';

    public const TECHNIQUE = 'technique';

    public const MATERIAL = 'material';

    public const ANSWER = 'answer';

    public const REPLY = 'reply';

    /** Kinds that start a thread. */
    public const THREAD_KINDS = [self::QUESTION, self::DISCUSSION, self::EXPLANATION, self::TECHNIQUE, self::MATERIAL];

    /** Kinds shown under the Discussions tab. */
    public const DISCUSSION_KINDS = [self::DISCUSSION, self::EXPLANATION, self::TECHNIQUE];

    public const VISIBLE = 'visible';

    public const HELD = 'held';

    public const HIDDEN = 'hidden';

    public const REMOVED = 'removed';

    protected $table = 'community_posts';

    protected $fillable = [
        'room_id', 'group_id', 'parent_id', 'author_id', 'kind', 'title', 'body', 'original_text', 'redactions',
        'original_locale', 'library_resource_id', 'status', 'held_reason', 'last_activity_at',
    ];

    protected $hidden = ['original_text'];

    protected function casts(): array
    {
        return [
            'original_text' => 'encrypted',
            'resolved_at' => 'datetime',
            'edited_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'redactions' => 'integer',
            'reporters_count' => 'integer',
        ];
    }

    public static function kindLabel(string $kind): string
    {
        return match ($kind) {
            self::QUESTION => 'Question',
            self::DISCUSSION => 'Discussion',
            self::EXPLANATION => 'Explanation',
            self::TECHNIQUE => 'Revision technique',
            self::MATERIAL => 'Study material',
            self::ANSWER => 'Answer',
            self::REPLY => 'Reply',
            default => ucfirst($kind),
        };
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /** @return BelongsTo<StudyGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(StudyGroup::class, 'group_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** @return BelongsTo<self, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<self, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return BelongsTo<self, $this> */
    public function acceptedAnswer(): BelongsTo
    {
        return $this->belongsTo(self::class, 'accepted_answer_id');
    }

    /** @return HasMany<PostRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(PostRevision::class)->orderByDesc('id');
    }

    /** Visible to everyone who can see the room, plus the author's own held posts. @param Builder<self> $query */
    public function scopeVisibleTo(Builder $query, ?User $user): void
    {
        $query->where(function (Builder $q) use ($user) {
            $q->where('status', self::VISIBLE);
            if ($user !== null) {
                $q->orWhere(fn (Builder $own) => $own->where('author_id', $user->id)->where('status', self::HELD));
            }
        });
    }

    public function isThread(): bool
    {
        return $this->parent_id === null;
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }

    public function isVisible(): bool
    {
        return $this->status === self::VISIBLE;
    }

    public function heldLabel(): ?string
    {
        if ($this->status !== self::HELD) {
            return null;
        }

        return match ($this->held_reason) {
            'pre_moderation' => 'A moderator checks posts from learners under 13 before others see them.',
            'reported', 'reported_child_safety' => 'Held for a moderator after reports. Others cannot see it until it is reviewed.',
            default => 'Held for a moderator. Others cannot see it until it is reviewed.',
        };
    }

    public function moderationHide(User $operator): void
    {
        $this->forceFill(['status' => self::HIDDEN])->save();
    }

    public function moderationRestore(User $operator): void
    {
        $this->forceFill(['status' => self::VISIBLE, 'held_reason' => null])->save();
    }

    public function reportedUser(): ?User
    {
        return $this->author;
    }

    /** @return list<int> */
    public function involvedUserIds(): array
    {
        return array_values(array_filter([$this->author_id, $this->parent?->author_id]));
    }

    /** @return array<string, mixed> */
    public function reportSnapshot(): array
    {
        return [
            'type' => 'community_post',
            'id' => $this->id,
            'room_id' => $this->room_id,
            'kind' => $this->kind,
            'title' => $this->title,
            'body' => mb_substr((string) $this->body, 0, 4000),
            'status' => $this->status,
        ];
    }
}
