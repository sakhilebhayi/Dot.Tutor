<?php

namespace App\Domain\Learning\Seams;

use App\Domain\Learning\Contracts\Seams\AuditTrail;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/** Default until the safety domain's audit log merges: writes the event to the application log. */
class LogAuditTrail implements AuditTrail
{
    public function record(?User $actor, string $action, ?Model $subject, array $context = []): void
    {
        Log::info('learning.audit', [
            'actor_id' => $actor?->id,
            'action' => $action,
            'subject' => $subject ? $subject::class.'#'.$subject->getKey() : null,
            'context' => $context,
        ]);
    }
}
