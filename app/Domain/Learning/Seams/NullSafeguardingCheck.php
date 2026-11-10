<?php

namespace App\Domain\Learning\Seams;

use App\Domain\Learning\Contracts\Seams\SafeguardingCheck;
use App\Domain\Learning\Support\GateDecision;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Default until the safety domain merges: no age-based rule exists yet, so nothing extra is refused here. The
 * learning domain's own checks (approved tutor to publish, a teaching relationship to assign work) still apply.
 */
class NullSafeguardingCheck implements SafeguardingCheck
{
    public function allows(User $actor, string $ability, ?Model $subject = null): GateDecision
    {
        return GateDecision::allow();
    }
}
