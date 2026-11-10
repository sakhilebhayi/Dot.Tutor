<?php

namespace App\Domain\Safety\Contracts;

use App\Domain\Safety\Models\GuardianApproval;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Bookings, purchases, paid rooms and live one-to-one lessons for minors wait for a linked guardian.
 * Kinds: booking, purchase, paid_room, live_lesson. A child's kinds always need approval; a teen's follow
 * config/safety.php and the guardian's own settings.
 */
interface GuardianApprovals
{
    public function requires(User $learner, string $kind): bool;

    /**
     * Ask the learner's guardians. Returns the open request for this subject if one is already pending.
     *
     * @param  array{summary?: string, amount_minor?: int, currency?: string}  $details
     */
    public function request(User $learner, string $kind, Model $subject, array $details = []): GuardianApproval;

    /** not_required, approved, pending, declined, expired, cancelled or none (never asked). */
    public function status(User $learner, string $kind, Model $subject): string;

    public function isApproved(User $learner, string $kind, Model $subject): bool;
}
