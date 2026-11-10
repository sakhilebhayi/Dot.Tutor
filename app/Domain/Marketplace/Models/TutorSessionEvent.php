<?php

namespace App\Domain\Marketplace\Models;

use App\Models\TutorSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Append-only history of a booking: every state change, who made it and why. */
class TutorSessionEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['tutor_session_id', 'participant_id', 'actor_id', 'event', 'from_state', 'to_state', 'reason', 'context'];

    protected $casts = ['context' => 'array'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Booking events are append-only.'));
        static::deleting(fn () => throw new LogicException('Booking events are append-only.'));
    }

    /** @return BelongsTo<TutorSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(TutorSession::class, 'tutor_session_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
