<?php

namespace App\Domain\Marketplace\Models;

use App\Models\TutorProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only audit record of an operator's verification decision (a
 * qualification verified or rejected, a level assigned or removed). Rows are
 * never updated or deleted through the application.
 */
class VerificationDecision extends Model
{
    protected $table = 'tutor_verification_decisions';

    public const UPDATED_AT = null;

    protected $fillable = ['tutor_profile_id', 'operator_id', 'subject', 'tutor_qualification_id', 'from_value', 'to_value', 'reason', 'evidence_ids'];

    protected $casts = ['evidence_ids' => 'array'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Verification decisions are append-only.'));
        static::deleting(fn () => throw new LogicException('Verification decisions are append-only.'));
    }

    /** @return BelongsTo<TutorProfile, $this> */
    public function tutorProfile(): BelongsTo
    {
        return $this->belongsTo(TutorProfile::class);
    }

    /** @return BelongsTo<User, $this> */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }
}
