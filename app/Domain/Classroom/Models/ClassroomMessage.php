<?php

namespace App\Domain\Classroom\Models;

use App\Domain\Safety\Contracts\Moderatable;
use App\Domain\Safety\Contracts\Reportable;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A chat message in a lesson. Stored, reportable, hideable by moderators; contact details hidden when a minor is in the room. */
class ClassroomMessage extends Model implements Moderatable, Reportable
{
    protected $fillable = ['classroom_id', 'user_id', 'body', 'original_body', 'redactions', 'hidden_at'];

    // The unredacted text is evidence for a safeguarding officer, never shown to the room.
    protected $hidden = ['original_body'];

    protected function casts(): array
    {
        return [
            'original_body' => 'encrypted',
            'redactions' => 'array',
            'hidden_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Classroom, $this> */
    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function moderationHide(User $operator): void
    {
        $this->forceFill(['hidden_at' => now()])->save();
    }

    public function moderationRestore(User $operator): void
    {
        $this->forceFill(['hidden_at' => null])->save();
    }

    public function reportedUser(): ?User
    {
        return $this->sender;
    }

    public function involvedUserIds(): array
    {
        return $this->classroom->safetyParticipantIds();
    }

    public function reportSnapshot(): array
    {
        return [
            'type' => 'classroom_message',
            'classroom_id' => $this->classroom_id,
            'tutor_session_id' => $this->classroom->tutor_session_id,
            'sender_id' => $this->user_id,
            'body' => $this->body,
            'sent_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
