<?php

namespace App\Domain\Marketplace\Seams;

use App\Domain\Marketplace\Contracts\SafeguardingPort;
use App\Domain\Marketplace\Support\GateDecision;
use App\Models\User;

/**
 * No safeguarding gate connected yet. Allows, and says why, so the gap is
 * visible in every decision rather than silent.
 */
class NullSafeguarding implements SafeguardingPort
{
    public function allows(User $actor, string $ability, mixed $subject = null): GateDecision
    {
        return GateDecision::allow('safeguarding_not_connected');
    }
}
