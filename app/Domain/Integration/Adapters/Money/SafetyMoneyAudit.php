<?php

namespace App\Domain\Integration\Adapters\Money;

use App\Domain\Money\Contracts\MoneyAudit;
use App\Domain\Safety\Audit;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Refunds, credits, payouts, commission rules and bank exports go to the safety audit log. */
class SafetyMoneyAudit implements MoneyAudit
{
    public function record(?User $actor, string $action, ?Model $subject, array $context = []): void
    {
        Audit::record($actor, $action, $subject, $context);
    }
}
