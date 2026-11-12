<?php

namespace App\Policies;

use App\Domain\Marketplace\Enums\SeatStatus;
use App\Enums\SessionStatus;
use App\Models\TutorSession;
use App\Models\User;

class TutorSessionPolicy
{
    /**
     * A session is only visible to the two people actually in it — the
     * student who booked it, or the tutor whose profile it's booked
     * against. No admin/staff role exists in this schema to justify a
     * wider view (see routes/web.php's /dashboard scoping for the same rule).
     */
    public function view(User $user, TutorSession $session): bool
    {
        return $session->student_id === $user->id
            || $session->tutorProfile->user_id === $user->id
            // R-47: a learner holding (or who held) a seat on a group lesson.
            || ($session->isGroup() && $session->participants()->where('learner_id', $user->id)->exists());
    }

    /**
     * Learner or tutor, while the booking is still ahead (not once it is under
     * way). A group lesson is cancelled whole only by its tutor; learners
     * cancel their seat instead.
     */
    public function cancel(User $user, TutorSession $session): bool
    {
        $cancellable = [
            SessionStatus::Pending->value, SessionStatus::AwaitingGuardian->value,
            SessionStatus::AwaitingPayment->value, SessionStatus::Confirmed->value,
        ];

        if (! in_array($session->status, $cancellable, true)) {
            return false;
        }

        return $session->isGroup()
            ? $session->tutorProfile->user_id === $user->id
            : ($session->student_id === $user->id || $session->tutorProfile->user_id === $user->id);
    }

    /**
     * Only the tutor confirms a booking -- the student already committed to
     * it by requesting the session; confirmation is the tutor accepting.
     */
    public function confirm(User $user, TutorSession $session): bool
    {
        return $session->tutorProfile->user_id === $user->id
            && $session->tutorProfile->isApproved()
            && $session->status === SessionStatus::Pending->value;
    }

    /**
     * Either party can mark a confirmed session completed, but only once
     * it has actually started -- prevents completing a session that
     * hasn't happened yet. Only while the tutor is still approved: a suspended
     * tutor's sessions can be cancelled but not completed (which would also
     * add to their total).
     */
    public function complete(User $user, TutorSession $session): bool
    {
        return $this->view($user, $session)
            && $session->tutorProfile->isApproved()
            && in_array($session->status, [SessionStatus::Confirmed->value, SessionStatus::InProgress->value], true)
            && now()->gte($session->starts_at);
    }

    /**
     * Only the student rates a session, not the tutor -- `session_ratings`
     * has a unique constraint on session_id (one rating per session, not
     * one per party), and the average feeds TutorProfile.rating, so it
     * only makes sense as "students rate the tutor they booked", matching
     * how every real tutoring marketplace (Wyzant, Preply) works.
     */
    public function rate(User $user, TutorSession $session): bool
    {
        if ($session->status !== SessionStatus::Completed->value) {
            return false;
        }

        // R-47: on a group lesson each attending learner rates their own seat.
        if ($session->isGroup()) {
            return $session->participants()->where('learner_id', $user->id)->where('status', SeatStatus::Attended->value)->exists()
                && ! $session->reviews()->where('rated_by', $user->id)->exists();
        }

        return $session->student_id === $user->id
            && ! $session->rating()->exists();
    }

    /**
     * Either party can attach a lesson resource once the session is
     * actually happening or has happened -- not while it's still a
     * speculative pending request, and not on a cancelled/no-show one.
     */
    public function uploadResource(User $user, TutorSession $session): bool
    {
        return $this->view($user, $session)
            && in_array($session->status, [SessionStatus::Confirmed->value, SessionStatus::Completed->value], true);
    }
}
