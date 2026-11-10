<?php

namespace App\Domain\Safety\Contracts;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only audit log for moderation, safeguarding, verification, money and admin actions.
 * Call through App\Domain\Safety\Audit::record(). Never put message bodies, documents or secrets in $context.
 */
interface AuditLog
{
    /** @param array<string, mixed> $context */
    public function record(?User $actor, string $action, Model|string|null $subject = null, array $context = []): void;
}
