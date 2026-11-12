<?php

namespace App\Domain\Safety\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Every conversation has a context: a booking (tutor_sessions row), a room (community, wave 2), support (a
 * person and the operators) or direct (adults only). There is no context-free chat with a minor.
 */
class Conversation extends Model
{
    public const BOOKING = 'booking';

    public const ROOM = 'room';

    public const SUPPORT = 'support';

    public const DIRECT = 'direct';

    protected $table = 'safety_conversations';

    protected $fillable = [
        'context_type', 'context_id', 'subject', 'created_by_id', 'involves_minor', 'closed_at', 'last_message_at',
    ];

    protected function casts(): array
    {
        return ['involves_minor' => 'boolean', 'closed_at' => 'datetime', 'last_message_at' => 'datetime'];
    }

    /** @return HasMany<ConversationParticipant, $this> */
    public function participants(): HasMany
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'safety_conversation_participants')
            ->withPivot(['role', 'guardian_of_id', 'last_read_at', 'left_at'])
            ->wherePivotNull('left_at');
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function participantFor(User $user): ?ConversationParticipant
    {
        return $this->participants()->where('user_id', $user->id)->whereNull('left_at')->first();
    }

    /** People in the conversation in their own right (not guardians or operators watching over it). @return list<int> */
    public function memberIds(): array
    {
        return $this->participants()->whereNull('left_at')->where('role', ConversationParticipant::MEMBER)
            ->pluck('user_id')->map(fn ($id) => (int) $id)->all();
    }

    /** @param Builder<Conversation> $query */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->whereHas('participants', fn (Builder $q) => $q->where('user_id', $user->id)->whereNull('left_at'));
    }
}
