<?php

namespace App\Domain\Integration\Adapters\Learning;

use App\Domain\Learning\Contracts\Seams\AuditTrail;
use App\Domain\Safety\Audit;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class SafetyAuditTrail implements AuditTrail
{
    public function record(?User $actor, string $action, ?Model $subject, array $context = []): void
    {
        Audit::record($actor, $action, $subject, $context);
    }
}
