<?php

namespace App\Domain\Integration\Adapters\Marketplace;

use App\Domain\Marketplace\Contracts\SafeguardingPort;
use App\Domain\Marketplace\Support\GateDecision;
use App\Domain\Safety\Contracts\SafeguardingGate;
use App\Models\User;

/**
 * The safety gate in the marketplace's shape. Reasons are the gate's plain-language messages (the marketplace shows
 * them). A requirement such as guardian_approval:booking is handled by the marketplace's guardian port, so an
 * allowed decision with a requirement is still "allowed" here.
 */
class SafetySafeguardingPort implements SafeguardingPort
{
    public function __construct(private readonly SafeguardingGate $gate) {}

    public function allows(User $actor, string $ability, mixed $subject = null, array $context = []): GateDecision
    {
        $decision = $this->gate->allows($actor, $ability, $subject, $context);
        $messages = array_column($decision->reasons, 'message');

        return $decision->allowed ? GateDecision::allow(...$messages) : GateDecision::deny(...$messages);
    }
}
