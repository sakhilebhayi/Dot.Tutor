<?php

namespace App\Domain\Marketplace\Models;

use App\Models\Subject;
use App\Models\TutorProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A subject the tutor teaches, optionally narrowed to a curriculum and a level range (academic ids). */
class TutorTeachingScope extends Model
{
    protected $fillable = ['tutor_profile_id', 'subject_id', 'curriculum_id', 'level_min_id', 'level_max_id', 'notes'];

    protected $casts = [
        'curriculum_id' => 'integer',
        'level_min_id' => 'integer',
        'level_max_id' => 'integer',
    ];

    /** @return BelongsTo<TutorProfile, $this> */
    public function tutorProfile(): BelongsTo
    {
        return $this->belongsTo(TutorProfile::class);
    }

    /** @return BelongsTo<Subject, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }
}
