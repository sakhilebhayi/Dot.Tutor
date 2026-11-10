<?php

namespace App\Domain\Academic\Contracts;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Operator changes to the academic structure and language verification are
 * audited. Bound to a log-backed default until the safety domain's
 * Audit::record lands and the integration provider rebinds this.
 */
interface AcademicAudit
{
    /** @param  array<string, mixed>  $context */
    public function record(?User $actor, string $action, ?Model $subject, array $context = []): void;
}
