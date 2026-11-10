<?php

namespace App\Domain\Marketplace\Contracts;

use App\Models\User;

/** Owned by safety as Safety\Audit::record(). */
interface AuditPort
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function record(?User $actor, string $action, string $subjectType, int|string|null $subjectId, array $context = []): void;
}
