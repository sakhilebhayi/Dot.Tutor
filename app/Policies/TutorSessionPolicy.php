<?php

namespace App\Policies;

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
            || $session->tutorProfile->user_id === $user->id;
    }

    public function cancel(User $user, TutorSession $session): bool
    {
        return $this->view($user, $session)
            && in_array($session->status, SessionStatus::holdingTime(), true);
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
            && $session->status === SessionStatus::Confirmed->value
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
        return $session->student_id === $user->id
            && $session->status === SessionStatus::Completed->value
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
