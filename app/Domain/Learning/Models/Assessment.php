<?php

namespace App\Domain\Learning\Models;

use App\Domain\Learning\Enums\AssessmentKind;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Assessment extends Model
{
    protected $table = 'learning_assessments';

    protected $fillable = [
        'owner_id', 'course_id', 'title', 'instructions', 'kind', 'pass_percent', 'time_limit_minutes',
        'max_attempts', 'shuffle_questions', 'reveal_answers',
    ];

    protected function casts(): array
    {
        return [
            'kind' => AssessmentKind::class,
            'shuffle_questions' => 'boolean',
            'pass_percent' => 'integer',
            'time_limit_minutes' => 'integer',
            'max_attempts' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    /** @return BelongsToMany<Question, $this> */
    public function questions(): BelongsToMany
    {
        return $this->belongsToMany(Question::class, 'learning_assessment_questions', 'assessment_id', 'question_id')
            ->withPivot('position')
            ->orderByPivot('position');
    }

    /** @return HasMany<Assignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class, 'assessment_id');
    }

    /** @return HasMany<Attempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(Attempt::class, 'assessment_id');
    }

    public function isOwnedBy(?User $user): bool
    {
        return $user !== null && $this->owner_id === $user->id;
    }
}
