<?php

namespace App\Models;

use App\Domain\Marketplace\Models\ReviewReport;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SessionRating extends Model
{
    protected $fillable = ['session_id', 'rated_by', 'rating', 'review'];

    protected $casts = [
        'rating' => 'integer',
        'tutor_replied_at' => 'datetime',
        'moderated_at' => 'datetime',
    ];

    /** @return BelongsTo<TutorSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(TutorSession::class, 'session_id');
    }

    /** @return BelongsTo<User, $this> */
    public function rater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rated_by');
    }

    /** @return HasMany<ReviewReport, $this> */
    public function reports(): HasMany
    {
        return $this->hasMany(ReviewReport::class, 'session_rating_id');
    }
}
