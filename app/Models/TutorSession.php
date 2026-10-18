<?php

namespace App\Models;

use App\Enums\SessionStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TutorSession extends Model
{
    protected $table = 'tutor_sessions';

    protected $fillable = [
        'tutor_profile_id', 'student_id', 'subject_id',
        'status', 'delivery', 'starts_at', 'duration_minutes', 'rate', 'currency', 'notes',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'rate' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        // The DB cascade removes lesson_resources rows without telling Eloquent,
        // which would orphan their files. Delete them as models first so the
        // LessonResource deleted hook removes each file.
        static::deleting(function (TutorSession $session) {
            $session->resources()->get()->each->delete();
        });

        // A deleted session takes its rating with it (DB cascade); keep the
        // tutor's average honest.
        static::deleted(function (TutorSession $session) {
            $session->tutorProfile?->refreshRating();
        });
    }

    /** @return BelongsTo<TutorProfile, $this> */
    public function tutorProfile(): BelongsTo
    {
        return $this->belongsTo(TutorProfile::class);
    }

    /** @return BelongsTo<User, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    /** @return BelongsTo<Subject, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /** @return HasMany<LessonResource, $this> */
    public function resources(): HasMany
    {
        return $this->hasMany(LessonResource::class, 'session_id');
    }

    /** @return HasOne<SessionRating, $this> */
    public function rating(): HasOne
    {
        return $this->hasOne(SessionRating::class, 'session_id');
    }

    public function endsAt(): CarbonInterface
    {
        return $this->starts_at->copy()->addMinutes($this->duration_minutes);
    }

    /** Rate x time: the value of the lesson at the rate snapshotted when it was booked. */
    public function amount(): float
    {
        return (float) $this->rate * $this->duration_minutes / 60;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, SessionStatus::holdingTime(), true);
    }
}
