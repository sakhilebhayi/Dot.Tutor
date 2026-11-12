<?php

namespace App\Domain\Learning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One learner's spaced-review state for one card (SM-2 style: repetitions, interval, ease, due date). */
class FlashcardReview extends Model
{
    protected $table = 'learning_flashcard_reviews';

    protected $fillable = [
        'flashcard_id', 'user_id', 'repetitions', 'interval_days', 'ease', 'due_at', 'last_reviewed_at', 'last_grade',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'last_reviewed_at' => 'datetime',
            'ease' => 'float',
            'repetitions' => 'integer',
            'interval_days' => 'integer',
        ];
    }

    /** @return BelongsTo<Flashcard, $this> */
    public function flashcard(): BelongsTo
    {
        return $this->belongsTo(Flashcard::class, 'flashcard_id');
    }
}
