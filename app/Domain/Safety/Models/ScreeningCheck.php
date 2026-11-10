<?php

namespace App\Domain\Safety\Models;

use App\Domain\Safety\Enums\ScreeningKind;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A screening check an operator did by hand (for example a Form 29 enquiry to the Department of Social
 * Development) and recorded here with its evidence. A record counts only once reviewed and cleared.
 */
class ScreeningCheck extends Model
{
    public const PENDING_REVIEW = 'pending_review';

    public const CLEARED = 'cleared';

    public const NOT_CLEARED = 'not_cleared';

    public const REVOKED = 'revoked';

    protected $table = 'safety_screening_checks';

    protected $fillable = [
        'user_id', 'kind', 'status', 'reference', 'checked_on', 'expires_on', 'evidence_path', 'evidence_name',
        'evidence_sha256', 'notes', 'recorded_by_id', 'reviewed_by_id', 'reviewed_at', 'review_note',
    ];

    protected $hidden = ['evidence_path'];

    protected function casts(): array
    {
        return [
            'kind' => ScreeningKind::class,
            'checked_on' => 'date',
            'expires_on' => 'date',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isCurrent(): bool
    {
        return $this->status === self::CLEARED
            && ($this->expires_on === null || $this->expires_on->endOfDay()->isFuture());
    }
}
