<?php

namespace App\Domain\Marketplace\Models;

use App\Domain\Marketplace\Enums\QualificationKind;
use App\Domain\Marketplace\Enums\QualificationStatus;
use App\Models\TutorProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A qualification or experience claim as a record. status and the review
 * columns are not fillable: only QualificationService (submit) and
 * VerificationService (verify/reject) move them.
 */
class TutorQualification extends Model
{
    protected $fillable = ['tutor_profile_id', 'kind', 'title', 'institution', 'year_awarded', 'reference', 'description'];

    protected $casts = [
        'year_awarded' => 'integer',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    protected $attributes = ['status' => 'unverified'];

    /** @return BelongsTo<TutorProfile, $this> */
    public function tutorProfile(): BelongsTo
    {
        return $this->belongsTo(TutorProfile::class);
    }

    /** @return HasMany<QualificationEvidence, $this> */
    public function evidence(): HasMany
    {
        return $this->hasMany(QualificationEvidence::class)->orderBy('id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function kindEnum(): QualificationKind
    {
        return QualificationKind::from($this->kind);
    }

    public function statusEnum(): QualificationStatus
    {
        return QualificationStatus::from($this->status);
    }

    public function isVerified(): bool
    {
        return $this->status === QualificationStatus::Verified->value;
    }
}
