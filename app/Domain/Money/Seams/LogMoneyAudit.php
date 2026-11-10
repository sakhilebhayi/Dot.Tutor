<?php

namespace App\Domain\Money\Seams;

use App\Domain\Money\Contracts\MoneyAudit;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Default audit sink until Safety\Audit is bound: one structured log line per money action, ids only.
 */
class LogMoneyAudit implements MoneyAudit
{
    public function record(?User $actor, string $action, ?Model $subject, array $context = []): void
    {
        Log::info('money.audit', [
            'action' => $action,
            'actor_id' => $actor?->id,
            'subject_type' => $subject === null ? null : class_basename($subject),
            'subject_id' => $subject?->getKey(),
            'context' => $context,
        ]);
    }
}
