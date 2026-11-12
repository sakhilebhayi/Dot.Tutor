<?php

namespace App\Domain\Marketplace\Models;

use App\Models\TutorProfile;
use App\Models\TutorSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A weekly recurring arrangement; each occurrence is a tutor_sessions row. */
class TutorBookingSeries extends Model
{
    protected $table = 'tutor_booking_series';

    protected $fillable = ['tutor_profile_id', 'learner_id', 'tutor_offering_id', 'subject_id', 'weekday', 'local_time', 'timezone', 'weeks', 'status'];

    protected $casts = ['weekday' => 'integer', 'weeks' => 'integer'];

    /** @return HasMany<TutorSession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(TutorSession::class, 'tutor_booking_series_id')->orderBy('starts_at');
    }

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

    /** @return BelongsTo<TutorOffering, $this> */
    public function offering(): BelongsTo
    {
        return $this->belongsTo(TutorOffering::class, 'tutor_offering_id');
    }
}
