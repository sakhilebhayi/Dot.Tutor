<?php

namespace App\Models;

use App\Domain\Marketplace\Models\TutorBookingSeries;
use App\Domain\Marketplace\Models\TutorOffering;
use App\Domain\Marketplace\Models\TutorPackagePurchase;
use App\Domain\Marketplace\Models\TutorSessionEvent;
use App\Domain\Marketplace\Models\TutorSessionParticipant;
use App\Domain\Marketplace\Support\MinorUnits;
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
        // R-47 booking v2 (marketplace)
        'rate_minor', 'price_minor', 'kind', 'capacity', 'payment_status', 'order_reference', 'guardian_status',
        'decision_reason', 'cancelled_at', 'cancelled_by', 'location_note', 'started_at', 'ended_at', 'hold_expires_at',
        'tutor_offering_id', 'tutor_booking_series_id', 'tutor_package_purchase_id',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'rate' => 'decimal:2',
        'rate_minor' => 'integer',
        'price_minor' => 'integer',
        'capacity' => 'integer',
        'cancelled_at' => 'datetime',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'hold_expires_at' => 'datetime',
    ];

    public const KIND_ONE_TO_ONE = 'one_to_one';

    public const KIND_GROUP = 'group';

    /**
     * The longest lesson any booking may hold, in minutes. Overlap checks narrow
     * candidates by start time using this bound, so it must cover every
     * duration an offering allows as well as the legacy duration list.
     */
    public static function longestDurationMinutes(): int
    {
        return max([...array_map('intval', (array) config('tutor.durations', [120])), (int) config('marketplace.max_duration_minutes', 240)]);
    }

    protected static function booted(): void
    {
        // Keep the decimal rate and its integer minor-unit companion in step.
        static::saving(function (TutorSession $session) {
            $currency = $session->currency ?: config('tutor.currency');
            if ($session->isDirty('rate_minor') && $session->rate_minor !== null && ! $session->isDirty('rate')) {
                $session->rate = MinorUnits::toDecimal((int) $session->rate_minor, $currency);
            } elseif ($session->isDirty('rate') && $session->rate !== null) {
                $session->rate_minor = MinorUnits::fromDecimal($session->rate, $currency);
            }
        });

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

    /** @return BelongsTo<TutorOffering, $this> */
    public function offering(): BelongsTo
    {
        return $this->belongsTo(TutorOffering::class, 'tutor_offering_id');
    }

    /** @return BelongsTo<TutorBookingSeries, $this> */
    public function series(): BelongsTo
    {
        return $this->belongsTo(TutorBookingSeries::class, 'tutor_booking_series_id');
    }

    /** @return BelongsTo<TutorPackagePurchase, $this> */
    public function packagePurchase(): BelongsTo
    {
        return $this->belongsTo(TutorPackagePurchase::class, 'tutor_package_purchase_id');
    }

    /** @return HasMany<TutorSessionParticipant, $this> */
    public function participants(): HasMany
    {
        return $this->hasMany(TutorSessionParticipant::class)->orderBy('id');
    }

    /** @return HasMany<TutorSessionEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(TutorSessionEvent::class)->orderBy('id');
    }

    /** @return HasMany<SessionRating, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(SessionRating::class, 'session_id');
    }

    public function isGroup(): bool
    {
        return $this->kind === self::KIND_GROUP;
    }

    public function statusEnum(): SessionStatus
    {
        return SessionStatus::from($this->status);
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
