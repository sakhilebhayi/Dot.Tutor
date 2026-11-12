<?php

namespace App\Domain\Money\Contracts;

use App\Domain\Money\ApprovalDecision;
use App\Domain\Money\Models\Order;
use App\Models\User;

/**
 * Money's side of guardian approval (brief section 15: guardian-approved payments for minors). The safety
 * domain owns Safety\Contracts\GuardianApprovals and SafeguardingGate; the integration agent binds an
 * adapter over them here. Money's own default (AgeBandPurchaseApprovals) lets adults pass and holds minors,
 * because no guardian link exists in the money domain to ask.
 */
interface PurchaseApprovals
{
    /** Decide when an order is created or payment is started: allowed, held for a guardian, or refused. */
    public function assess(User $buyer, Order $order): ApprovalDecision;

    /** May this person pay for the order (the buyer, or the buyer's approving guardian)? */
    public function canPayFor(User $actor, Order $order): bool;
}
