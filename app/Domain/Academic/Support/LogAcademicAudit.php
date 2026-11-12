<?php

namespace App\Domain\Academic\Support;

use App\Domain\Academic\Contracts\AcademicAudit;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/** Default audit: one structured line in the application log (no audit table exists yet in this domain). */
class LogAcademicAudit implements AcademicAudit
{
    public function record(?User $actor, string $action, ?Model $subject, array $context = []): void
    {
        Log::info('academic.audit', [
            'actor_id' => $actor?->id,
            'action' => $action,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'context' => $context,
        ]);
    }
}
