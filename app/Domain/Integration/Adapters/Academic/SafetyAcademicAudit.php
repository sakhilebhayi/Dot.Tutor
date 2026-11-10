<?php

namespace App\Domain\Integration\Adapters\Academic;

use App\Domain\Academic\Contracts\AcademicAudit;
use App\Domain\Safety\Audit;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Operator changes to the academic structure go to the safety domain's hash-chained audit log. */
class SafetyAcademicAudit implements AcademicAudit
{
    public function record(?User $actor, string $action, ?Model $subject, array $context = []): void
    {
        Audit::record($actor, $action, $subject, $context);
    }
}
