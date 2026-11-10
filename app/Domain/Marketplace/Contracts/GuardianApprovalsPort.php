<?php

namespace App\Domain\Marketplace\Contracts;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Guardian approval for a minor's booking or purchase. Owned by safety as
 * Safety\Contracts\GuardianApprovals. When the guardian decides, the safety
 * side calls App\Domain\Marketplace\Services\BookingFlow::applyGuardianDecision().
 */
interface GuardianApprovalsPort
{
    public const NOT_REQUIRED = 'not_required';

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const DECLINED = 'declined';

    /**
     * What approval this learner needs for this booking or purchase right now.
     *
     * @param  'booking'|'seat'|'package'  $kind
     * @return 'not_required'|'pending'|'approved'|'declined'
     */
    public function requirement(User $learner, string $kind, Model $subject): string;

    /** Ask the learner's guardian to approve. Returns a plain sentence for the learner about what happens next. */
    public function request(User $learner, string $kind, Model $subject): string;
}
