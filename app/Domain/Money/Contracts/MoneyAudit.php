<?php

namespace App\Domain\Money\Contracts;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Audit trail for money actions (refunds, credits, payouts, commission rules, bank exports). The integration
 * agent binds Safety\Audit::record here; the default writes a structured log line with ids only.
 *
 * @phpstan-type Context array<string, scalar|null>
 */
interface MoneyAudit
{
    /**
     * @param  array<string, scalar|null>  $context
     */
    public function record(?User $actor, string $action, ?Model $subject, array $context = []): void;
}
