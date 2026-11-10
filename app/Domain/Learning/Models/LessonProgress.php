<?php

namespace App\Domain\Learning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonProgress extends Model
{
    protected $table = 'learning_lesson_progress';

    protected $fillable = ['enrolment_id', 'lesson_id', 'position_seconds', 'completed_at'];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }

    /** @return BelongsTo<Enrolment, $this> */
    public function enrolment(): BelongsTo
    {
        return $this->belongsTo(Enrolment::class, 'enrolment_id');
    }
}
