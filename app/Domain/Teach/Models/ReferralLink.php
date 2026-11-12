<?php

namespace App\Domain\Teach\Models;

use App\Models\TutorProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A tutor's referral link (/r/{code}). Attribution only: a visit is counted and the visitor lands on the storefront.
 * No credit, discount or payment is attached, because the money domain has no referral credit rule (D-1209).
 */
class ReferralLink extends Model
{
    protected $table = 'teach_referral_links';

    protected $fillable = ['tutor_profile_id', 'code'];

    public static function for(TutorProfile $profile): self
    {
        return self::query()->firstOrCreate(
            ['tutor_profile_id' => $profile->id],
            ['code' => Str::lower(Str::random(10))],
        );
    }

    /** @return BelongsTo<TutorProfile, $this> */
    public function tutorProfile(): BelongsTo
    {
        return $this->belongsTo(TutorProfile::class);
    }

    /** @return HasMany<ReferralVisit, $this> */
    public function visits(): HasMany
    {
        return $this->hasMany(ReferralVisit::class, 'teach_referral_link_id');
    }
}
