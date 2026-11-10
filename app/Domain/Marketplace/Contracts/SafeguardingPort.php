<?php

namespace App\Domain\Marketplace\Contracts;

use App\Domain\Marketplace\Support\GateDecision;
use App\Models\User;

/**
 * Owned by safety as Safety\Contracts\SafeguardingGate. Every booking (book,
 * meet_live) asks it server-side before acting.
 */
interface SafeguardingPort
{
    public function allows(User $actor, string $ability, mixed $subject = null): GateDecision;
}
