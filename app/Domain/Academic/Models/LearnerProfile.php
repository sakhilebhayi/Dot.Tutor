<?php

namespace App\Domain\Academic\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Where a learner is academically, and the minimum personal data age banding
 * needs. `birth_month` is always the 1st of the month; see ageBandBirthDate().
 */
class LearnerProfile extends Model
{
    protected $fillable = ['country_id', 'curriculum_id', 'academic_level_id', 'institution_id', 'birth_month', 'bilingual_ok', 'onboarded_at'];

    protected $casts = [
        'birth_month' => 'immutable_date',
        'bilingual_ok' => 'boolean',
        'onboarded_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Country, $this> */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /** @return BelongsTo<Curriculum, $this> */
    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(Curriculum::class);
    }

    /** @return BelongsTo<AcademicLevel, $this> */
    public function level(): BelongsTo
    {
        return $this->belongsTo(AcademicLevel::class, 'academic_level_id');
    }

    /** @return BelongsTo<Institution, $this> */
    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    /** @return BelongsToMany<Subject, $this> */
    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'learner_profile_subjects')
            ->withPivot('curriculum_subject_level_id')
            ->withTimestamps();
    }

    /**
     * The date age banding uses: the LAST day of the birth month, so the
     * person is never treated as older than they are (a learner turning 13
     * in March is treated as 12 until the end of March).
     */
    public function ageBandBirthDate(): ?CarbonImmutable
    {
        return $this->birth_month?->endOfMonth()->startOfDay();
    }

    public function isOnboarded(): bool
    {
        return $this->onboarded_at !== null;
    }
}
