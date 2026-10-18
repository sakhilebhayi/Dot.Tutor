<?php

namespace App\Models;

use App\Enums\TutorProfileStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Facades\DB;

class TutorProfile extends Model
{
    protected $fillable = [
        'user_id', 'headline', 'bio', 'qualifications', 'hourly_rate', 'currency', 'timezone',
        'rating', 'total_sessions', 'status',
        'reviewed_by', 'reviewed_at', 'rejected_reason', 'suspended_reason',
    ];

    protected $casts = [
        'hourly_rate' => 'decimal:2',
        'rating' => 'decimal:2',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsToMany<Subject, $this> */
    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'tutor_subject');
    }

    /** @return HasMany<TutorSession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(TutorSession::class);
    }

    /** @return HasMany<TutorAvailabilityWindow, $this> */
    public function availabilityWindows(): HasMany
    {
        return $this->hasMany(TutorAvailabilityWindow::class)->orderBy('weekday')->orderBy('start_time');
    }

    /** @return HasMany<TutorBlockedDate, $this> */
    public function blockedDates(): HasMany
    {
        return $this->hasMany(TutorBlockedDate::class)->orderBy('date');
    }

    /** @return HasManyThrough<SessionRating, TutorSession, $this> */
    public function ratings(): HasManyThrough
    {
        return $this->hasManyThrough(SessionRating::class, TutorSession::class, 'tutor_profile_id', 'session_id');
    }

    public function isApproved(): bool
    {
        return $this->status === TutorProfileStatus::Approved->value;
    }

    /** The IANA zone this tutor's weekly hours are written in. */
    public function scheduleTimezone(): string
    {
        return $this->timezone ?: config('tutor.timezone');
    }

    /**
     * The reason an operator gave for the current non-approved state, if any,
     * so the tutor can see why they are not on the marketplace.
     */
    public function decisionReason(): ?string
    {
        return match ($this->status) {
            TutorProfileStatus::Rejected->value => $this->rejected_reason,
            TutorProfileStatus::Suspended->value => $this->suspended_reason,
            default => null,
        };
    }

    /**
     * Recompute the average from every review this tutor has received.
     * Idempotent: it derives the value from the ratings table rather than
     * adjusting a running figure, so repeating it (retries, double clicks,
     * backfills) always lands on the same number. Eloquent skips the UPDATE
     * when the value is unchanged.
     */
    public function refreshRating(): void
    {
        $this->update(['rating' => round((float) ($this->ratings()->avg('rating') ?? 0), 2)]);
    }

    /**
     * Run a callback while holding a row lock on this profile, so concurrent
     * bookings for the same tutor are checked one at a time. SQLite has no
     * row locks (the clause is dropped); PostgreSQL/MySQL take FOR UPDATE.
     *
     * @template T
     *
     * @param  callable(self): T  $callback
     * @return T
     */
    public function withLock(callable $callback): mixed
    {
        return DB::transaction(function () use ($callback) {
            $locked = static::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();

            return $callback($locked);
        });
    }
}
