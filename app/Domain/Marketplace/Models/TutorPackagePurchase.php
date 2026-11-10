<?php

namespace App\Domain\Marketplace\Models;

use App\Models\TutorProfile;
use App\Models\TutorSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A block of lesson credits bought from a package offering. */
class TutorPackagePurchase extends Model
{
    public const AWAITING_GUARDIAN = 'awaiting_guardian';

    public const AWAITING_PAYMENT = 'awaiting_payment';

    public const ACTIVE = 'active';

    public const EXHAUSTED = 'exhausted';

    public const EXPIRED = 'expired';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'tutor_profile_id', 'learner_id', 'tutor_offering_id', 'lessons_total', 'lessons_used', 'lesson_minutes',
        'price_minor', 'currency', 'status', 'payment_status', 'order_reference', 'guardian_status', 'expires_at',
    ];

    protected $casts = [
        'lessons_total' => 'integer',
        'lessons_used' => 'integer',
        'lesson_minutes' => 'integer',
        'price_minor' => 'integer',
        'expires_at' => 'datetime',
    ];

    public function creditsLeft(): int
    {
        return max(0, $this->lessons_total - $this->lessons_used);
    }

    /** @return BelongsTo<TutorOffering, $this> */
    public function offering(): BelongsTo
    {
        return $this->belongsTo(TutorOffering::class, 'tutor_offering_id');
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

    /** @return HasMany<TutorSession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(TutorSession::class, 'tutor_package_purchase_id');
    }
}
