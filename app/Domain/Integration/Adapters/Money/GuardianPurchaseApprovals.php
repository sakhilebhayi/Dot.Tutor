<?php

namespace App\Domain\Integration\Adapters\Money;

use App\Domain\Integration\Support\MoneyTypes;
use App\Domain\Money\ApprovalDecision;
use App\Domain\Money\Contracts\PurchaseApprovals;
use App\Domain\Money\Models\Order;
use App\Domain\Money\Money;
use App\Domain\Safety\Contracts\GuardianApprovals;
use App\Domain\Safety\Contracts\SafeguardingGate;
use App\Domain\Safety\Models\GuardianApproval;
use App\Domain\Safety\Services\Guardians;
use App\Models\User;

/**
 * Money's guardian rule backed by the safety domain. The gate decides whether the buyer may pay at all (a limited
 * minor or a suspended account is refused) and whether a guardian must approve (a requirement on the decision).
 * A required approval is asked for once per order through GuardianApprovals; the guardian's answer comes back
 * through the integration listener into Orders::recordGuardianDecision. A package the guardian already approved as
 * a purchase (marketplace) counts for the order that pays for it, so the guardian is not asked twice.
 */
class GuardianPurchaseApprovals implements PurchaseApprovals
{
    public const HELD_MESSAGE = 'A parent or guardian must approve this purchase before it can be paid. We have asked them.';

    public function __construct(
        private readonly SafeguardingGate $gate,
        private readonly GuardianApprovals $approvals,
        private readonly Guardians $guardians,
    ) {}

    public function assess(User $buyer, Order $order): ApprovalDecision
    {
        if ($order->guardian_status === 'refused') {
            return ApprovalDecision::refused('A parent or guardian declined this purchase.');
        }

        $decision = $this->gate->allows($buyer, 'pay', $order, ['kind' => 'purchase']);

        if ($decision->denied()) {
            return ApprovalDecision::refused($decision->message());
        }

        if (! $decision->requires('guardian_approval:purchase')) {
            return ApprovalDecision::allowed($order->guardian_status === 'approved' ? 'approved' : 'not_needed');
        }

        if ($this->coveredByApprovedItems($buyer, $order)) {
            return ApprovalDecision::allowed('approved');
        }

        $status = $this->approvals->status($buyer, 'purchase', $order);

        if ($status === GuardianApproval::DECLINED) {
            return ApprovalDecision::refused('A parent or guardian declined this purchase.');
        }

        if ($status === GuardianApproval::PENDING) {
            $open = GuardianApproval::query()->where('learner_id', $buyer->id)->where('kind', 'purchase')
                ->where('subject_type', $order->getMorphClass())->where('subject_id', $order->getKey())
                ->orderByDesc('id')->first();

            return ApprovalDecision::held(self::HELD_MESSAGE, $open ? 'approval:'.$open->id : null);
        }

        // Never asked, expired or cancelled: ask (again).
        $order->loadMissing('lines');
        $approval = $this->approvals->request($buyer, 'purchase', $order, [
            'summary' => mb_substr($order->lines->pluck('title')->implode(', '), 0, 255),
            'amount_minor' => (int) $order->total_minor,
            'currency' => $order->currency,
        ]);

        return ApprovalDecision::held(self::HELD_MESSAGE.' Total: '.Money::of((int) $order->total_minor, $order->currency)->format().'.', 'approval:'.$approval->id);
    }

    public function canPayFor(User $actor, Order $order): bool
    {
        if ($actor->id === $order->buyer_id) {
            return true;
        }

        $buyer = $order->buyer;

        return $buyer !== null && $this->guardians->isGuardianOf($actor, $buyer);
    }

    /** Every line is a marketplace package the guardian has already approved as a purchase. */
    private function coveredByApprovedItems(User $buyer, Order $order): bool
    {
        $order->loadMissing('lines');

        if ($order->lines->isEmpty()) {
            return false;
        }

        foreach ($order->lines as $line) {
            $subject = MoneyTypes::guardianSubject($line->purchasable_type, (int) $line->purchasable_id);

            if ($subject === null || ! $this->approvals->isApproved($buyer, 'purchase', $subject)) {
                return false;
            }
        }

        return true;
    }
}
