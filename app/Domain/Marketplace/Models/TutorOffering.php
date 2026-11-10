<?php

namespace App\Domain\Marketplace\Models;

use App\Domain\Marketplace\Enums\OfferingType;
use App\Domain\Marketplace\Enums\TeachingFormat;
use App\Models\Subject;
use App\Models\TutorProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class TutorOffering extends Model
{
    protected $fillable = [
        'tutor_profile_id', 'type', 'title', 'description', 'price_minor', 'currency', 'duration_minutes',
        'format', 'area', 'capacity', 'package_lessons', 'package_valid_days', 'recurring_weeks', 'level_ids',
        'cancellation_notice_hours', 'late_cancellation_refund_percent', 'is_active',
    ];

    protected $casts = [
        'price_minor' => 'integer',
        'duration_minutes' => 'integer',
        'capacity' => 'integer',
        'package_lessons' => 'integer',
        'package_valid_days' => 'integer',
        'recurring_weeks' => 'integer',
        'level_ids' => 'array',
        'cancellation_notice_hours' => 'integer',
        'late_cancellation_refund_percent' => 'integer',
        'is_active' => 'boolean',
    ];

    /** @return BelongsTo<TutorProfile, $this> */
    public function tutorProfile(): BelongsTo
    {
        return $this->belongsTo(TutorProfile::class);
    }

    /** @return BelongsToMany<Subject, $this> */
    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'tutor_offering_subject');
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function typeEnum(): OfferingType
    {
        return OfferingType::from($this->type);
    }

    public function formatEnum(): TeachingFormat
    {
        return TeachingFormat::from($this->format);
    }

    /** Price per hour in minor units, for budget comparisons (a package compares per lesson). */
    public function hourlyEquivalentMinor(): int
    {
        $perLesson = $this->type === OfferingType::Package->value && $this->package_lessons
            ? intdiv($this->price_minor, max(1, $this->package_lessons))
            : $this->price_minor;

        return (int) round($perLesson * 60 / max(1, $this->duration_minutes));
    }

    public function teachesLevel(?int $levelId): bool
    {
        $levels = (array) ($this->level_ids ?? []);

        return $levelId === null || $levels === [] || in_array($levelId, array_map('intval', $levels), true);
    }
}
