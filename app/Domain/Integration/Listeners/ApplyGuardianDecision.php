<?php

namespace App\Domain\Integration\Listeners;

use App\Domain\Marketplace\Enums\SeatStatus;
use App\Domain\Marketplace\Exceptions\MarketplaceException;
use App\Domain\Marketplace\Models\TutorBookingSeries;
use App\Domain\Marketplace\Models\TutorPackagePurchase;
use App\Domain\Marketplace\Models\TutorSessionParticipant;
use App\Domain\Marketplace\Services\BookingFlow;
use App\Domain\Money\Models\Order;
use App\Domain\Money\Services\Orders;
use App\Domain\Safety\Models\GuardianApproval;
use App\Domain\Safety\Services\DatabaseGuardianApprovals;
use App\Enums\SessionStatus;
use App\Models\TutorSession;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * A guardian's decision (made on the safety dashboard) carried to what it was about:
 *  - a marketplace lesson, group seat or package waiting for a guardian: BookingFlow::applyGuardianDecision;
 *  - a weekly series: the same decision on each lesson of the series still waiting (each lesson has its own
 *    approval, created by the safety backstop; deciding them keeps the confirmation check honest);
 *  - a money order held for a guardian: Orders::recordGuardianDecision.
 * Runs on the approval row's update, inside the guardian's request.
 */
class ApplyGuardianDecision
{
    public function __construct(
        private readonly BookingFlow $bookings,
        private readonly Orders $orders,
        private readonly DatabaseGuardianApprovals $approvals,
    ) {}

    public function handle(GuardianApproval $approval): void
    {
        if (! $approval->wasChanged('status') || ! in_array($approval->status, [GuardianApproval::APPROVED, GuardianApproval::DECLINED], true)) {
            return;
        }

        $approved = $approval->status === GuardianApproval::APPROVED;
        $guardian = $approval->decided_by_id ? User::query()->find($approval->decided_by_id) : null;
        $subject = $approval->subject()->first();
        $reason = $approved ? null : ($approval->note ?: 'A parent or guardian declined.');

        try {
            match (true) {
                $subject instanceof TutorSession => $subject->status === SessionStatus::AwaitingGuardian->value
                    ? $this->bookings->applyGuardianDecision($subject, $approved, $guardian, $reason) : null,
                $subject instanceof TutorSessionParticipant => $subject->status === SeatStatus::AwaitingGuardian->value
                    ? $this->bookings->applyGuardianDecision($subject, $approved, $guardian, $reason) : null,
                $subject instanceof TutorPackagePurchase => $subject->status === TutorPackagePurchase::AWAITING_GUARDIAN
                    ? $this->bookings->applyGuardianDecision($subject, $approved, $guardian, $reason) : null,
                $subject instanceof TutorBookingSeries => $this->decideSeries($subject, $approval, $guardian, $approved),
                $subject instanceof Order => $this->orders->recordGuardianDecision($subject, $approved, 'approval:'.$approval->id),
                default => null,
            };
        } catch (MarketplaceException $e) {
            // The booking moved on meanwhile (cancelled, expired): the decision has nothing left to change.
            Log::info('integration.guardian_decision.stale', ['approval_id' => $approval->id, 'error' => $e->error]);
        }
    }

    private function decideSeries(TutorBookingSeries $series, GuardianApproval $approval, ?User $guardian, bool $approved): void
    {
        if ($guardian === null) {
            return;
        }

        foreach (TutorSession::query()->where('tutor_booking_series_id', $series->id)->orderBy('id')->get() as $session) {
            $open = GuardianApproval::query()->where('learner_id', $approval->learner_id)->where('kind', 'booking')
                ->where('subject_type', $session->getMorphClass())->where('subject_id', $session->id)
                ->orderByDesc('id')->first();

            if ($open !== null && $open->effectiveStatus() === GuardianApproval::PENDING) {
                $this->approvals->decide($open, $guardian, $approved, 'Decided with the weekly series.');
            } elseif ($session->status === SessionStatus::AwaitingGuardian->value) {
                $this->bookings->applyGuardianDecision($session, $approved, $guardian, $approved ? null : 'A parent or guardian declined the series.');
            }
        }
    }
}
