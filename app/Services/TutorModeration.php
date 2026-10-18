<?php

namespace App\Services;

use App\Enums\SessionStatus;
use App\Enums\TutorProfileStatus;
use App\Models\TutorProfile;
use App\Models\TutorSession;
use App\Models\User;
use App\Notifications\SessionCancelledByModeration;
use App\Notifications\TutorProfileDecision;
use Illuminate\Support\Facades\DB;

/**
 * Operator decisions on tutor profiles. Each returns false (and changes
 * nothing) when the profile is not in a state that decision applies to, so a
 * double click or a stale tab is a no-op, not a corrupt status.
 */
class TutorModeration
{
    public function approve(TutorProfile $profile, User $operator): bool
    {
        return $this->transition($profile, TutorProfileStatus::Pending, [
            'status' => TutorProfileStatus::Approved->value,
            'reviewed_by' => $operator->id,
            'reviewed_at' => now(),
            'rejected_reason' => null,
        ], TutorProfileDecision::APPROVED);
    }

    public function reject(TutorProfile $profile, User $operator, string $reason): bool
    {
        return $this->transition($profile, TutorProfileStatus::Pending, [
            'status' => TutorProfileStatus::Rejected->value,
            'rejected_reason' => $reason,
            'reviewed_by' => $operator->id,
            'reviewed_at' => now(),
        ], TutorProfileDecision::REJECTED, $reason);
    }

    /**
     * Suspending also cancels the tutor's PENDING requests (confirmed ones are
     * left for the two people to sort out; either can still cancel). Done here,
     * in the same transaction, rather than by a scheduled sweep, so there is no
     * window in which a suspended tutor still holds students' requests.
     */
    public function suspend(TutorProfile $profile, User $operator, string $reason): bool
    {
        return $this->transition($profile, TutorProfileStatus::Approved, [
            'status' => TutorProfileStatus::Suspended->value,
            'suspended_reason' => $reason,
            'reviewed_by' => $operator->id,
            'reviewed_at' => now(),
        ], TutorProfileDecision::SUSPENDED, $reason, function (TutorProfile $locked) {
            $locked->sessions()
                ->where('status', SessionStatus::Pending->value)
                ->with('student')
                ->get()
                ->each(function (TutorSession $session) {
                    $session->update(['status' => SessionStatus::Cancelled->value]);
                    $session->student?->notify(new SessionCancelledByModeration($session));
                });
        });
    }

    public function reinstate(TutorProfile $profile, User $operator): bool
    {
        return $this->transition($profile, TutorProfileStatus::Suspended, [
            'status' => TutorProfileStatus::Approved->value,
            'suspended_reason' => null,
            'reviewed_by' => $operator->id,
            'reviewed_at' => now(),
        ], TutorProfileDecision::REINSTATED);
    }

    /**
     * Called when the tutor's own account is being deleted: take the profile off
     * the marketplace and cancel every future open session, but keep the row
     * (and so every student's history).
     */
    public function closeAccount(TutorProfile $profile): void
    {
        $profile->update([
            'status' => TutorProfileStatus::Suspended->value,
            'suspended_reason' => 'Account closed by its owner.',
        ]);

        $profile->sessions()
            ->whereIn('status', SessionStatus::holdingTime())
            ->where('starts_at', '>', now())
            ->update(['status' => SessionStatus::Cancelled->value]);
    }

    /**
     * @param  array<string, mixed>  $changes
     * @param  (callable(TutorProfile): void)|null  $after
     */
    private function transition(TutorProfile $profile, TutorProfileStatus $from, array $changes, string $decision, ?string $reason = null, ?callable $after = null): bool
    {
        return DB::transaction(function () use ($profile, $from, $changes, $decision, $reason, $after) {
            $locked = TutorProfile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== $from->value) {
                return false;
            }

            $locked->update($changes);
            if ($after !== null) {
                $after($locked);
            }
            $locked->user?->notify(new TutorProfileDecision($locked, $decision, $reason));

            return true;
        });
    }
}
