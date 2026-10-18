<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A whole local date (in the tutor's timezone) with no bookings. Kept as a
 * plain 'Y-m-d' string on purpose: it is a calendar date, not an instant.
 */
class TutorBlockedDate extends Model
{
    protected $fillable = ['tutor_profile_id', 'date', 'reason'];

    /** @return BelongsTo<TutorProfile, $this> */
    public function tutorProfile(): BelongsTo
    {
        return $this->belongsTo(TutorProfile::class);
    }
}
