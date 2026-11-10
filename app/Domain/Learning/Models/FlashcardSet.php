<?php

namespace App\Domain\Learning\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FlashcardSet extends Model
{
    protected $table = 'learning_flashcard_sets';

    protected $fillable = ['owner_id', 'title', 'subject_id', 'topic', 'language', 'is_shared'];

    protected function casts(): array
    {
        return ['is_shared' => 'boolean'];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return HasMany<Flashcard, $this> */
    public function cards(): HasMany
    {
        return $this->hasMany(Flashcard::class, 'set_id')->orderBy('position')->orderBy('id');
    }
}
