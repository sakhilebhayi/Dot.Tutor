<?php

namespace App\Domain\Student\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** How the learner screens look to one person (D-1102). step: auto | young | standard. */
class StudentPreference extends Model
{
    public const STEPS = ['auto', 'young', 'standard'];

    protected $fillable = ['user_id', 'step'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
