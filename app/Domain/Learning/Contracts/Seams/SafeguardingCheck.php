<?php

namespace App\Domain\Learning\Contracts\Seams;

use App\Domain\Learning\Support\GateDecision;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** The learning side of Safety\Contracts\SafeguardingGate (abilities used here: publish, pay, assign_work). */
interface SafeguardingCheck
{
    public function allows(User $actor, string $ability, ?Model $subject = null): GateDecision;
}
