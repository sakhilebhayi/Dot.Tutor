<?php

namespace App\Domain\Learning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttemptAnswer extends Model
{
    protected $table = 'learning_attempt_answers';

    protected $fillable = [
        'attempt_id', 'question_id', 'user_id', 'subject_id', 'strand', 'topic', 'response', 'is_correct',
        'marks_awarded', 'marks_possible', 'counts_for_mastery', 'answered_at',
    ];

    protected function casts(): array
    {
        return [
            'response' => 'array',
            'is_correct' => 'boolean',
            'counts_for_mastery' => 'boolean',
            'answered_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Question, $this> */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class, 'question_id');
    }
}
