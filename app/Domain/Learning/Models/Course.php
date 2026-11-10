<?php

namespace App\Domain\Learning\Models;

use App\Domain\Learning\Contracts\Sellable;
use App\Domain\Learning\Enums\CompletionRule;
use App\Domain\Learning\Enums\CourseStatus;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Course extends Model implements Sellable
{
    protected $table = 'learning_courses';

    protected $fillable = [
        'owner_id', 'slug', 'title', 'description', 'subject_id', 'curriculum_id', 'level_id', 'original_locale',
        'objectives', 'prerequisites', 'workload_minutes', 'pricing', 'price_minor', 'currency', 'refund_policy',
        'completion_rule', 'status', 'published_at', 'current_version_id',
    ];

    protected function casts(): array
    {
        return [
            'objectives' => 'array',
            'prerequisites' => 'array',
            'status' => CourseStatus::class,
            'completion_rule' => CompletionRule::class,
            'published_at' => 'datetime',
            'price_minor' => 'integer',
            'workload_minutes' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<Subject, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /** @return HasMany<CourseSection, $this> */
    public function sections(): HasMany
    {
        return $this->hasMany(CourseSection::class, 'course_id')->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<Lesson, $this> */
    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class, 'course_id');
    }

    /** @return HasMany<CourseMaterial, $this> */
    public function materials(): HasMany
    {
        return $this->hasMany(CourseMaterial::class, 'course_id');
    }

    /** @return HasMany<CourseVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(CourseVersion::class, 'course_id')->orderBy('number');
    }

    /** @return BelongsTo<CourseVersion, $this> */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(CourseVersion::class, 'current_version_id');
    }

    /** @return HasMany<Enrolment, $this> */
    public function enrolments(): HasMany
    {
        return $this->hasMany(Enrolment::class, 'course_id');
    }

    /** @return BelongsToMany<Bundle, $this> */
    public function bundles(): BelongsToMany
    {
        return $this->belongsToMany(Bundle::class, 'learning_bundle_courses', 'course_id', 'bundle_id');
    }

    /** @return HasMany<CourseReview, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(CourseReview::class, 'course_id');
    }

    public function isFree(): bool
    {
        return $this->pricing === 'free';
    }

    public function isPublished(): bool
    {
        return $this->status === CourseStatus::Published;
    }

    public function isOwnedBy(?User $user): bool
    {
        return $user !== null && $this->owner_id === $user->id;
    }

    public function sellableType(): string
    {
        return 'course';
    }

    public function sellableId(): int
    {
        return (int) $this->getKey();
    }

    public function priceMinor(): int
    {
        return $this->isFree() ? 0 : (int) $this->price_minor;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function sellerId(): ?int
    {
        return $this->owner_id;
    }

    public function sellableDescription(): string
    {
        return 'Course: '.$this->title;
    }
}
