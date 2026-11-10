<?php

namespace App\Domain\Safety\Services;

use App\Domain\Safety\Audit;
use App\Domain\Safety\Contracts\GuardianApprovals;
use App\Domain\Safety\Enums\AgeBand;
use App\Domain\Safety\Models\GuardianApproval;
use App\Domain\Safety\Models\GuardianLink;
use App\Domain\Safety\Notifications\GuardianApprovalDecided;
use App\Domain\Safety\Notifications\GuardianApprovalRequested;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class DatabaseGuardianApprovals implements GuardianApprovals
{
    public function __construct(
        private readonly AgeResolver $ages,
        private readonly Guardians $guardians,
    ) {}

    public function requires(User $learner, string $kind): bool
    {
        $this->assertKind($kind);
        $band = $this->ages->band($learner);

        if (! $band->isMinor()) {
            return false;
        }

        if ($band === AgeBand::Child) {
            return true;
        }

        // A teen: the strictest of the guardians' own settings, else the configured default.
        $default = (bool) config("safety.approvals.teen.$kind", true);
        $overrides = GuardianLink::query()->active()->where('learner_id', $learner->id)->pluck('approval_settings')
            ->map(fn ($settings) => is_array($settings) && array_key_exists($kind, $settings) ? (bool) $settings[$kind] : $default);

        return $overrides->isEmpty() ? $default : $overrides->contains(true);
    }

    public function request(User $learner, string $kind, Model $subject, array $details = []): GuardianApproval
    {
        $this->assertKind($kind);

        $open = $this->latest($learner, $kind, $subject);

        if ($open && $open->effectiveStatus() === GuardianApproval::PENDING) {
            return $open;
        }

        $approval = GuardianApproval::create([
            'learner_id' => $learner->id,
            'kind' => $kind,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'status' => GuardianApproval::PENDING,
            'summary' => isset($details['summary']) ? mb_substr((string) $details['summary'], 0, 255) : null,
            'amount_minor' => $details['amount_minor'] ?? null,
            'currency' => $details['currency'] ?? null,
            'expires_at' => now()->addHours((int) config('safety.approvals.expiry_hours')),
        ]);

        $guardians = $this->guardians->guardiansOf($learner);

        if ($guardians->isNotEmpty()) {
            Notification::send($guardians, new GuardianApprovalRequested($approval, $learner->name));
        }

        Audit::record($learner, 'safety.approval.requested', $approval, [
            'kind' => $kind, 'subject_type' => $approval->subject_type, 'subject_id' => $approval->subject_id,
        ]);

        return $approval;
    }

    public function status(User $learner, string $kind, Model $subject): string
    {
        if (! $this->requires($learner, $kind)) {
            return 'not_required';
        }

        return $this->latest($learner, $kind, $subject)?->effectiveStatus() ?? 'none';
    }

    public function isApproved(User $learner, string $kind, Model $subject): bool
    {
        return in_array($this->status($learner, $kind, $subject), ['not_required', GuardianApproval::APPROVED], true);
    }

    public function decide(GuardianApproval $approval, User $guardian, bool $approve, ?string $note = null): GuardianApproval
    {
        if (! $this->guardians->isGuardianOf($guardian, $approval->learner)) {
            abort(403, 'Only a linked guardian of this learner can decide.');
        }

        if ($approval->effectiveStatus() !== GuardianApproval::PENDING) {
            throw ValidationException::withMessages(['approval' => 'This request is no longer waiting for a decision.']);
        }

        $approval->forceFill([
            'status' => $approve ? GuardianApproval::APPROVED : GuardianApproval::DECLINED,
            'decided_at' => now(),
            'decided_by_id' => $guardian->id,
            'note' => $note !== null ? mb_substr($note, 0, 500) : null,
        ])->save();

        $approval->learner->notify(new GuardianApprovalDecided($approval));

        Audit::record($guardian, $approve ? 'safety.approval.approved' : 'safety.approval.declined', $approval, [
            'learner_id' => $approval->learner_id, 'kind' => $approval->kind,
        ]);

        return $approval;
    }

    public function cancel(User $learner, string $kind, Model $subject): void
    {
        GuardianApproval::query()->where('learner_id', $learner->id)->where('kind', $kind)
            ->where('subject_type', $subject->getMorphClass())->where('subject_id', $subject->getKey())
            ->where('status', GuardianApproval::PENDING)
            ->update(['status' => GuardianApproval::CANCELLED, 'updated_at' => now()]);
    }

    /** Mark lapsed requests expired. Returns how many. */
    public function expireDue(): int
    {
        return GuardianApproval::query()->where('status', GuardianApproval::PENDING)
            ->where('expires_at', '<=', now())
            ->update(['status' => GuardianApproval::EXPIRED, 'updated_at' => now()]);
    }

    private function latest(User $learner, string $kind, Model $subject): ?GuardianApproval
    {
        return GuardianApproval::query()->where('learner_id', $learner->id)->where('kind', $kind)
            ->where('subject_type', $subject->getMorphClass())->where('subject_id', $subject->getKey())
            ->orderByDesc('id')->first();
    }

    private function assertKind(string $kind): void
    {
        if (! in_array($kind, config('safety.approvals.kinds'), true)) {
            throw new InvalidArgumentException("Unknown guardian approval kind [$kind].");
        }
    }
}
