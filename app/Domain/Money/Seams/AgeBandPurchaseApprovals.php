<?php

namespace App\Domain\Money\Seams;

use App\Domain\Money\ApprovalDecision;
use App\Domain\Money\Contracts\BuyerAge;
use App\Domain\Money\Contracts\PurchaseApprovals;
use App\Domain\Money\Models\Order;
use App\Models\User;

/**
 * Money's default approval rule: adults pass; a child or teen is held for a guardian. An unknown age is held
 * only when money.guardian.hold_unknown_age is true. Nothing here can contact a guardian (the money domain has
 * no guardian link), so a held order stays held until the safety domain, bound by the integration agent,
 * records the guardian's decision through App\Domain\Money\Services\Orders::recordGuardianDecision.
 */
class AgeBandPurchaseApprovals implements PurchaseApprovals
{
    public const HELD_MESSAGE = 'A parent or guardian must approve this purchase before it can be paid.';

    public function __construct(private BuyerAge $age) {}

    public function assess(User $buyer, Order $order): ApprovalDecision
    {
        if ($order->guardian_status === 'approved') {
            return ApprovalDecision::allowed('approved');
        }

        if ($order->guardian_status === 'refused') {
            return ApprovalDecision::refused('A parent or guardian declined this purchase.');
        }

        $band = $this->age->bandFor($buyer);

        if ($band === 'adult' || ($band === null && ! config('money.guardian.hold_unknown_age'))) {
            return ApprovalDecision::allowed();
        }

        return ApprovalDecision::held(self::HELD_MESSAGE);
    }

    public function canPayFor(User $actor, Order $order): bool
    {
        return $actor->id === $order->buyer_id;
    }
}
