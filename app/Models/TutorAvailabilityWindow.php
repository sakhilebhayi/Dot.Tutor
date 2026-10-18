<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One block of weekly recurring hours. weekday is ISO-8601 (1 Monday ..
 * 7 Sunday); start_time/end_time are local 'HH:MM' in the tutor's timezone.
 */
class TutorAvailabilityWindow extends Model
{
    protected $fillable = ['tutor_profile_id', 'weekday', 'start_time', 'end_time'];

    protected $casts = ['weekday' => 'integer'];

    public const WEEKDAYS = [
        1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday',
        5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday',
    ];

    /** @return BelongsTo<TutorProfile, $this> */
    public function tutorProfile(): BelongsTo
    {
        return $this->belongsTo(TutorProfile::class);
    }
}
