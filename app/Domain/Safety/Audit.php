<?php

namespace App\Domain\Safety;

use App\Domain\Safety\Contracts\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Audit::record($actor, 'safety.report.filed', $report, ['category' => 'spam']).
 *
 * Action names are dotted: <domain>.<thing>.<verb>. Context is metadata (ids, codes, amounts in minor units),
 * never message text, document contents or secrets.
 */
final class Audit
{
    /** @param array<string, mixed> $context */
    public static function record(?User $actor, string $action, Model|string|null $subject = null, array $context = []): void
    {
        app(AuditLog::class)->record($actor, $action, $subject, $context);
    }
}
