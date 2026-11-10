<?php

namespace App\Domain\Academic\Models;

use App\Domain\Academic\Enums\LanguageRole;
use App\Domain\Academic\Enums\TeachingProficiency;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One language a person holds in one role. `verified_at` is set by an operator only. */
class UserLanguage extends Model
{
    protected $fillable = ['user_id', 'language_id', 'role', 'proficiency'];

    protected $casts = [
        'role' => LanguageRole::class,
        'proficiency' => TeachingProficiency::class,
        'verified_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Language, $this> */
    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }
}
