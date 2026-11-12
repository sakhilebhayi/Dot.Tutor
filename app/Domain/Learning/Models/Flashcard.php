<?php

namespace App\Domain\Learning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Flashcard extends Model
{
    protected $table = 'learning_flashcards';

    protected $fillable = ['set_id', 'front', 'back', 'position'];

    /** @return BelongsTo<FlashcardSet, $this> */
    public function set(): BelongsTo
    {
        return $this->belongsTo(FlashcardSet::class, 'set_id');
    }
}
