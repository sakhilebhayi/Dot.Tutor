<?php

namespace App\Models;

use App\Domain\Marketplace\Enums\ReviewStatus;
use App\Domain\Marketplace\Models\ReviewReport;
use App\Domain\Marketplace\Services\ReviewService;
use App\Domain\Safety\Contracts\Moderatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SessionRating extends Model implements Moderatable
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

    /** Safety moderation queue "hide": the review is removed through the marketplace's own moderation (audited). */
    public function moderationHide(User $operator): void
    {
        app(ReviewService::class)->moderate($this, $operator, ReviewStatus::Removed, 'Hidden from the safety moderation queue after a report.');
    }

    public function moderationRestore(User $operator): void
    {
        app(ReviewService::class)->moderate($this, $operator, ReviewStatus::Published, 'Restored from the safety moderation queue.');
    }
}
