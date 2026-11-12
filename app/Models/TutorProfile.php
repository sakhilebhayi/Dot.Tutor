<?php

namespace App\Models;

use App\Domain\Marketplace\Enums\ReviewStatus;
use App\Domain\Marketplace\Enums\VerificationLevel;
use App\Domain\Marketplace\Models\TutorOffering;
use App\Domain\Marketplace\Models\TutorQualification;
use App\Domain\Marketplace\Models\TutorTeachingScope;
use App\Domain\Marketplace\Models\VerificationDecision;
use App\Domain\Marketplace\Support\MinorUnits;
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
        // R-47 profile v2 (marketplace). verification_level* are NOT fillable: only VerificationService sets them.
        'hourly_rate_minor', 'teaching_approach', 'experience_summary', 'years_experience',
        'teaches_online', 'teaches_in_person', 'in_person_area', 'group_size_min', 'group_size_max', 'public_handle',
    ];

    protected $casts = [
        'hourly_rate' => 'decimal:2',
        'rating' => 'decimal:2',
        'hourly_rate_minor' => 'integer',
        'years_experience' => 'integer',
        'teaches_online' => 'boolean',
        'teaches_in_person' => 'boolean',
        'group_size_min' => 'integer',
        'group_size_max' => 'integer',
        'verification_level_set_at' => 'datetime',
        'photo_updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Keep the decimal rate and its integer minor-unit companion in step,
        // whichever one the caller wrote (the legacy form writes the decimal,
        // the v2 API writes minor units).
        static::saving(function (TutorProfile $profile) {
            $currency = $profile->currency ?: config('tutor.currency');
            if ($profile->isDirty('hourly_rate_minor') && $profile->hourly_rate_minor !== null && ! $profile->isDirty('hourly_rate')) {
                $profile->hourly_rate = MinorUnits::toDecimal((int) $profile->hourly_rate_minor, $currency);
            } elseif ($profile->isDirty('hourly_rate') && $profile->hourly_rate !== null) {
                $profile->hourly_rate_minor = MinorUnits::fromDecimal($profile->hourly_rate, $currency);
            }
        });
    }

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

    /** @return HasMany<TutorQualification, $this> */
    public function qualificationRecords(): HasMany
    {
        return $this->hasMany(TutorQualification::class)->orderBy('id');
    }

    /** @return HasMany<TutorTeachingScope, $this> */
    public function teachingScopes(): HasMany
    {
        return $this->hasMany(TutorTeachingScope::class)->orderBy('id');
    }

    /** @return HasMany<TutorOffering, $this> */
    public function offerings(): HasMany
    {
        return $this->hasMany(TutorOffering::class)->orderBy('id');
    }

    /** @return HasMany<VerificationDecision, $this> */
    public function verificationDecisions(): HasMany
    {
        return $this->hasMany(VerificationDecision::class)->orderBy('id');
    }

    public function verificationLevel(): ?VerificationLevel
    {
        return VerificationLevel::tryFrom((string) $this->verification_level);
    }

    public function displayName(): string
    {
        return optional($this->user)->name ?? 'Deleted account';
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
        // Only published reviews count: a review held for moderation or removed never moves the average.
        $this->update(['rating' => round((float) ($this->ratings()->where('session_ratings.status', ReviewStatus::Published->value)->avg('rating') ?? 0), 2)]);
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
