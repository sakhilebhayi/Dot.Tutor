<?php

namespace App\Domain\Safety\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GuardianLink extends Model
{
    public const ACTIVE = 'active';

    public const REVOKED = 'revoked';

    protected $table = 'safety_guardian_links';

    protected $fillable = [
        'learner_id', 'guardian_id', 'invitation_id', 'relationship', 'status', 'approval_settings',
        'revoked_at', 'revoked_by_id', 'revoke_reason',
    ];

    protected function casts(): array
    {
        return ['approval_settings' => 'array', 'revoked_at' => 'datetime'];
    }

    /** @param Builder<GuardianLink> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', self::ACTIVE);
    }

    /** @return BelongsTo<User, $this> */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'learner_id');
    }

    /** @return BelongsTo<User, $this> */
    public function guardian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'guardian_id');
    }

    /** @return HasMany<Consent, $this> */
    public function consents(): HasMany
    {
        return $this->hasMany(Consent::class, 'guardian_link_id');
    }
}
