<?php

namespace App\Domain\Integration\Adapters\Marketplace;

use App\Domain\Marketplace\Contracts\AuditPort;
use App\Domain\Safety\Audit;
use App\Models\User;

/**
 * The marketplace names its subject by type and id; the audit log stores the type as the subject and the id in the
 * context (the marketplace also keeps its own append-only verification and booking event tables).
 */
class SafetyAuditPort implements AuditPort
{
    public function record(?User $actor, string $action, string $subjectType, int|string|null $subjectId, array $context = []): void
    {
        Audit::record($actor, $action, 'marketplace.'.$subjectType, ['subject_id' => $subjectId] + $context);
    }
}
