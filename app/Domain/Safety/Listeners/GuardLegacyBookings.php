<?php

namespace App\Domain\Safety\Listeners;

use App\Domain\Safety\Contracts\GuardianApprovals;
use App\Domain\Safety\Contracts\SafeguardingGate;
use App\Domain\Safety\Services\AccountStanding;
use App\Domain\Safety\Support\Decision;
use App\Domain\Safety\Support\SafeguardingDenied;
use App\Enums\SessionStatus;
use App\Models\TutorSession;
use App\Models\User;

/**
 * A server-side backstop on the existing booking model (tutor_sessions), whoever writes it: a booking the gate
 * refuses is never created; a minor's booking asks the guardian on creation; and it cannot become confirmed until
 * the guardian has approved. Marketplace booking v2 also calls the gate itself; this catches any path that does not.
 */
class GuardLegacyBookings
{
    public function __construct(
        private readonly SafeguardingGate $gate,
        private readonly GuardianApprovals $approvals,
        private readonly AccountStanding $standing,
    ) {}

    public function creating(TutorSession $session): void
    {
        $student = User::find($session->student_id);

        if ($student === null || $session->tutorProfile === null) {
            return;
        }

        $this->gate->allows($student, 'book', $session->tutorProfile)->authorize();
    }

    public function created(TutorSession $session): void
    {
        $student = User::find($session->student_id);

        if ($student === null || ! $this->standing->isMinor($student) || ! $this->approvals->requires($student, 'booking')) {
            return;
        }

        $this->approvals->request($student, 'booking', $session, [
            'summary' => trim(($session->subject?->name ?? 'Lesson').' with '.($session->tutorProfile?->user?->name ?? 'a tutor')
                .' on '.$session->starts_at->timezone(config('tutor.timezone'))->format('j M Y H:i')),
        ]);
    }

    public function updating(TutorSession $session): void
    {
        if (! $session->isDirty('status') || $session->status !== SessionStatus::Confirmed->value) {
            return;
        }

        $student = User::find($session->student_id);

        if ($student !== null && ! $this->approvals->isApproved($student, 'booking', $session)) {
            throw new SafeguardingDenied(Decision::deny('guardian_approval_missing', 'A parent or guardian must approve this booking before it is confirmed.'));
        }
    }
}
