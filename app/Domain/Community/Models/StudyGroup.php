<?php

namespace App\Domain\Community\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A study group inside a room: a topic, an exam focus, a size cap and a join rule. */
class StudyGroup extends Model
{
    protected $table = 'community_groups';

    protected $fillable = ['room_id', 'owner_id', 'name', 'topic', 'exam_focus', 'schedule_note', 'size_cap', 'join_rule', 'invite_code', 'status'];

    protected $hidden = ['invite_code'];

    protected function casts(): array
    {
        return ['size_cap' => 'integer', 'members_count' => 'integer'];
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return HasMany<GroupMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(GroupMember::class, 'group_id');
    }

    /** @return HasMany<StudySession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(StudySession::class, 'group_id')->orderBy('starts_at');
    }

    /** @return HasMany<GroupResource, $this> */
    public function resources(): HasMany
    {
        return $this->hasMany(GroupResource::class, 'group_id')->orderByDesc('id');
    }

    /** @return HasMany<RevisionItem, $this> */
    public function revisionItems(): HasMany
    {
        return $this->hasMany(RevisionItem::class, 'group_id')->orderBy('id');
    }

    /** @return HasMany<GroupTask, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(GroupTask::class, 'group_id')->orderByRaw('due_on is null')->orderBy('due_on')->orderBy('id');
    }

    public function isFull(): bool
    {
        return $this->members_count >= $this->size_cap;
    }

    public function joinRuleLabel(): string
    {
        return match ($this->join_rule) {
            Room::OPEN => 'Anyone in the room can join',
            Room::APPROVAL => 'The organiser accepts each request',
            Room::INVITE => 'By invitation code only',
            default => $this->join_rule,
        };
    }
}
