<?php

namespace App\Domain\Teach\Models;

use App\Models\TutorProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The tutor's answers in the guided onboarding that no domain stores (teaches minors, when it was sent). */
class Onboarding extends Model
{
    protected $table = 'teach_onboarding';

    protected $fillable = ['tutor_profile_id', 'teaches_minors', 'submitted_at', 'resubmission_note'];

    protected $casts = ['teaches_minors' => 'boolean', 'submitted_at' => 'datetime'];

    /** @return BelongsTo<TutorProfile, $this> */
    public function tutorProfile(): BelongsTo
    {
        return $this->belongsTo(TutorProfile::class);
    }
}
