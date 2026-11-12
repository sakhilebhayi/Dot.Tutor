<?php

namespace App\Domain\Marketplace\Seams;

use App\Domain\Marketplace\Contracts\GuardianApprovalsPort;
use App\Domain\Marketplace\Contracts\LearnerAgePort;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * No guardian system yet. Adults (and learners whose age is not known) need no
 * approval; a known minor is HELD as awaiting_guardian, because nobody can
 * approve for them until the safety domain is connected. Never auto-approves.
 */
class NullGuardianApprovals implements GuardianApprovalsPort
{
    public function __construct(private LearnerAgePort $age) {}

    public function requirement(User $learner, string $kind, Model $subject): string
    {
        return $this->age->isMinor($learner) === true ? self::PENDING : self::NOT_REQUIRED;
    }

    public function request(User $learner, string $kind, Model $subject): string
    {
        return 'This booking needs a guardian\'s approval, and guardian approvals are not available on Dot.Tutor yet. It stays on hold until they are.';
    }
}
