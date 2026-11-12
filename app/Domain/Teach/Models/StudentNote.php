<?php

namespace App\Domain\Teach\Models;

use App\Models\TutorProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A tutor's private note about one of their own students. Only that tutor reads it; the learner never sees it. */
class StudentNote extends Model
{
    protected $table = 'teach_student_notes';

    protected $fillable = ['tutor_profile_id', 'learner_id', 'body'];

    /** @return BelongsTo<TutorProfile, $this> */
    public function tutorProfile(): BelongsTo
    {
        return $this->belongsTo(TutorProfile::class);
    }

    /** @return BelongsTo<User, $this> */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'learner_id');
    }
}
