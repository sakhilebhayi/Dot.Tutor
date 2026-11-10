<?php

namespace App\Domain\Safety\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class GuardianApproval extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const DECLINED = 'declined';

    public const EXPIRED = 'expired';

    public const CANCELLED = 'cancelled';

    protected $table = 'safety_guardian_approvals';

    protected $fillable = [
        'learner_id', 'kind', 'subject_type', 'subject_id', 'status', 'summary', 'amount_minor', 'currency',
        'expires_at', 'decided_at', 'decided_by_id', 'note',
    ];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'decided_at' => 'datetime', 'amount_minor' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'learner_id');
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** The status as of now: a pending request past its expiry reads as expired even before the sweep runs. */
    public function effectiveStatus(): string
    {
        if ($this->status === self::PENDING && $this->expires_at->isPast()) {
            return self::EXPIRED;
        }

        return $this->status;
    }
}
