<?php

namespace App\Domain\Integration\Adapters\Marketplace;

use App\Domain\Marketplace\Contracts\GuardianApprovalsPort;
use App\Domain\Marketplace\Models\TutorPackagePurchase;
use App\Domain\Safety\Contracts\GuardianApprovals;
use App\Domain\Safety\Models\GuardianApproval;
use App\Models\TutorSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Marketplace guardian holds backed by the safety domain. A lesson or a group seat is a "booking" approval; a
 * package is a "purchase" approval (so its payment is not asked about again). The guardian decides on the safety
 * dashboard; the integration listener carries the decision back to BookingFlow::applyGuardianDecision.
 */
class SafetyGuardianApprovals implements GuardianApprovalsPort
{
    public function __construct(private readonly GuardianApprovals $approvals) {}

    public function requirement(User $learner, string $kind, Model $subject): string
    {
        $safetyKind = $this->kind($kind);

        if (! $this->approvals->requires($learner, $safetyKind)) {
            return self::NOT_REQUIRED;
        }

        // The subject is the offering or group lesson being booked: a new booking always needs its own approval,
        // unless this exact subject is already decided.
        return match ($this->approvals->status($learner, $safetyKind, $subject)) {
            GuardianApproval::APPROVED => self::APPROVED,
            default => self::PENDING,
        };
    }

    public function request(User $learner, string $kind, Model $subject): string
    {
        $this->approvals->request($learner, $this->kind($kind), $subject, $this->details($subject));

        return 'We have asked your parent or guardian to approve this. It stays on hold until they answer (requests expire after '
            .(int) config('safety.approvals.expiry_hours').' hours).';
    }

    private function kind(string $kind): string
    {
        return $kind === 'package' ? 'purchase' : 'booking';
    }

    /** @return array{summary?: string, amount_minor?: int, currency?: string} */
    private function details(Model $subject): array
    {
        return match (true) {
            $subject instanceof TutorSession => array_filter([
                'summary' => 'Lesson on '.$subject->starts_at?->timezone(config('tutor.timezone'))->format('j M Y H:i'),
                'amount_minor' => $subject->price_minor !== null ? (int) $subject->price_minor : null,
                'currency' => $subject->currency,
            ], fn ($v) => $v !== null),
            $subject instanceof TutorPackagePurchase => [
                'summary' => $subject->lessons_total.'-lesson package',
                'amount_minor' => (int) $subject->price_minor,
                'currency' => (string) $subject->currency,
            ],
            default => isset($subject->price_minor) ? ['amount_minor' => (int) $subject->price_minor, 'currency' => (string) ($subject->currency ?? 'ZAR')] : [],
        };
    }
}
