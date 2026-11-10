<?php

namespace App\Domain\Safety\Models;

use App\Domain\Safety\Contracts\Moderatable;
use App\Domain\Safety\Contracts\Reportable;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Message extends Model implements Moderatable, Reportable
{
    protected $table = 'safety_messages';

    protected $fillable = [
        'conversation_id', 'sender_id', 'body', 'original_body', 'redactions', 'hidden_at', 'hidden_by_id',
    ];

    // The unredacted text is evidence for a safeguarding officer, never shown to participants.
    protected $hidden = ['original_body'];

    protected function casts(): array
    {
        return [
            'original_body' => 'encrypted',
            'redactions' => 'array',
            'hidden_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /** @return HasMany<MessageAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class);
    }

    public function isHidden(): bool
    {
        return $this->hidden_at !== null;
    }

    public function moderationHide(User $operator): void
    {
        $this->forceFill(['hidden_at' => now(), 'hidden_by_id' => $operator->id])->save();
    }

    public function moderationRestore(User $operator): void
    {
        $this->forceFill(['hidden_at' => null, 'hidden_by_id' => null])->save();
    }

    public function reportedUser(): ?User
    {
        return $this->sender;
    }

    /** @return list<int> */
    public function involvedUserIds(): array
    {
        return $this->conversation->memberIds();
    }

    public function reportSnapshot(): array
    {
        return [
            'conversation_id' => $this->conversation_id,
            'context' => $this->conversation->context_type,
            'sender_id' => $this->sender_id,
            'body' => $this->body,
            'redacted' => ! empty($this->redactions),
            'sent_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
