<?php

namespace App\Domain\Marketplace\Seams;

use App\Domain\Marketplace\Contracts\AuditPort;
use App\Models\User;

/**
 * No platform audit log yet. Marketplace keeps its own append-only records
 * (tutor_verification_decisions, tutor_session_events), so nothing is lost.
 */
class NullAudit implements AuditPort
{
    public function record(?User $actor, string $action, string $subjectType, int|string|null $subjectId, array $context = []): void {}
}
