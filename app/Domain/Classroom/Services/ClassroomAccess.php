<?php

namespace App\Domain\Classroom\Services;

use App\Domain\Classroom\Models\Classroom;
use App\Domain\Classroom\Models\ClassroomAttendee;
use App\Domain\Classroom\Support\Admission;
use App\Domain\Marketplace\Enums\SeatStatus;
use App\Domain\Safety\Contracts\GuardianApprovals;
use App\Domain\Safety\Contracts\SafeguardingGate;
use App\Domain\Safety\Services\Guardians;
use App\Enums\SessionStatus;
use App\Models\TutorSession;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Who may be in a lesson's classroom, and when. Every rule is server-side; the screens only show the answer.
 *
 * 1. A participant: the tutor, the one-to-one learner, a learner holding a confirmed group seat, or a linked guardian of
 *    a minor learner in the lesson (to observe).
 * 2. The booking is confirmed or in progress (the marketplace state machine decides that, not us).
 * 3. The room is open: from open_minutes_before the start until grace_minutes_after the end.
 * 4. The safeguarding gate allows meet_live (guardian consent, screening of adults with minors, the guardian's
 *    approval of a live lesson for a child, account suspension).
 */
class ClassroomAccess
{
    public function __construct(
        private readonly SafeguardingGate $gate,
        private readonly GuardianApprovals $approvals,
        private readonly Guardians $guardians,
    ) {}

    /** tutor | learner | guardian | null */
    public function roleOf(User $user, TutorSession $session, ?Classroom $classroom = null): ?string
    {
        $classroom ??= new Classroom(['tutor_session_id' => $session->id]);
        $classroom->setRelation('session', $session);

        if ($classroom->tutorUserId() === $user->id) {
            return 'tutor';
        }

        $learners = $classroom->learnerIds();
        if (in_array($user->id, $learners, true)) {
            return 'learner';
        }

        foreach ($learners as $learnerId) {
            $learner = User::query()->find($learnerId);
            if ($learner && $this->guardians->isGuardianOf($user, $learner)) {
                return 'guardian';
            }
        }

        return null;
    }

    public function opensAt(TutorSession $session): CarbonInterface
    {
        return $session->starts_at->copy()->subMinutes((int) config('classroom.open_minutes_before'));
    }

    public function closesAt(TutorSession $session): CarbonInterface
    {
        return $session->endsAt()->copy()->addMinutes((int) config('classroom.grace_minutes_after'));
    }

    /** May this person enter the room now? (Viewing the lesson's pre-join and after-lesson pages needs only a role.) */
    public function admit(User $user, TutorSession $session, ?Classroom $classroom = null): Admission
    {
        $opens = $this->opensAt($session);
        $closes = $this->closesAt($session);
        $role = $this->roleOf($user, $session, $classroom);

        if ($role === null) {
            return Admission::deny('not_participant', 'Only the tutor and the learners booked into this lesson can join it.');
        }

        $status = $session->statusEnum();
        if (in_array($status, [SessionStatus::Completed, SessionStatus::Cancelled, SessionStatus::NoShow, SessionStatus::Disputed], true)) {
            return Admission::deny('lesson_over', match ($status) {
                SessionStatus::Cancelled => 'This lesson was cancelled.',
                SessionStatus::Completed => 'This lesson is finished.',
                default => 'This lesson is closed.',
            }, $role, $opens, $closes);
        }
        if (! in_array($status, [SessionStatus::Confirmed, SessionStatus::InProgress], true)) {
            return Admission::deny('not_confirmed', 'The classroom opens once the lesson is confirmed (accepted, approved and paid where needed).', $role, $opens, $closes);
        }

        if (now()->lt($opens)) {
            return Admission::deny('too_early', 'The classroom opens '.config('classroom.open_minutes_before').' minutes before the lesson starts.', $role, $opens, $closes);
        }
        if (now()->gt($closes)) {
            return Admission::deny('closed', 'The classroom closed '.config('classroom.grace_minutes_after').' minutes after the lesson ended.', $role, $opens, $closes);
        }

        if ($classroom !== null) {
            if ($classroom->isEnded() && $role !== 'tutor') {
                return Admission::deny('ended', 'The tutor ended this lesson.', $role, $opens, $closes);
            }
            $removed = ClassroomAttendee::query()->where('classroom_id', $classroom->id)->where('user_id', $user->id)->where('state', 'removed')->exists();
            if ($removed) {
                return Admission::deny('removed', 'The tutor removed you from this lesson. Contact support if you think this is a mistake.', $role, $opens, $closes);
            }
        }

        $decision = $this->gate->allows($user, 'meet_live', $this->liveSubject($session, $classroom));
        if ($decision->denied()) {
            return Admission::deny($decision->codes()[0] ?? 'safeguarding_denied', $decision->message(), $role, $opens, $closes);
        }

        return Admission::allow($role, $opens, $closes);
    }

    /**
     * The subject the gate and the guardian approvals are asked about: the booking for a one-to-one lesson, the
     * classroom (which lists everyone booked in) for a group, so a guardian approves the lesson their child is in.
     */
    public function liveSubject(TutorSession $session, ?Classroom $classroom): Model
    {
        if (! $session->isGroup()) {
            return $session;
        }

        return $classroom ?? app(ClassroomService::class)->for($session);
    }

    /**
     * Each minor learner's live-lesson approval: required or not, and its state.
     *
     * @return list<array{learner_id: int, name: string, required: bool, status: string}>
     */
    public function approvals(TutorSession $session, Classroom $classroom): array
    {
        $subject = $this->liveSubject($session, $classroom);
        $rows = [];

        foreach ($classroom->learnerIds() as $learnerId) {
            $learner = User::query()->find($learnerId);
            if (! $learner || ! $this->approvals->requires($learner, 'live_lesson')) {
                continue;
            }
            $rows[] = [
                'learner_id' => $learner->id,
                'name' => $learner->name,
                'required' => true,
                'status' => $this->approvals->status($learner, 'live_lesson', $subject),
            ];
        }

        return $rows;
    }

    /**
     * Ask the guardians of every minor in the lesson who still needs a live-lesson approval. A learner asks for
     * themselves; the tutor may ask for any learner in the lesson. Returns how many requests are now open.
     */
    public function requestApprovals(User $actor, TutorSession $session, Classroom $classroom): int
    {
        $role = $this->roleOf($actor, $session, $classroom);
        $subject = $this->liveSubject($session, $classroom);
        $asked = 0;
        $when = $session->starts_at->copy()->timezone(config('app.timezone'))->format('j M Y, H:i');

        foreach ($classroom->learnerIds() as $learnerId) {
            if ($role === 'learner' && $learnerId !== $actor->id) {
                continue;
            }
            if ($role !== 'learner' && $role !== 'tutor') {
                continue;
            }
            $learner = User::query()->find($learnerId);
            if (! $learner || ! $this->approvals->requires($learner, 'live_lesson')) {
                continue;
            }
            $status = $this->approvals->status($learner, 'live_lesson', $subject);
            if (in_array($status, ['approved', 'not_required'], true)) {
                continue;
            }
            $this->approvals->request($learner, 'live_lesson', $subject, [
                'summary' => 'Live video lesson'.($session->subject?->name ? ' in '.$session->subject->name : '').' on '.$when.' in the Dot.Tutor classroom (camera, microphone, chat and whiteboard; not recorded).',
            ]);
            $asked++;
        }

        return $asked;
    }

    /** A learner in a group needs a confirmed seat; returns the seat state for the screens. */
    public function seatStatus(User $user, TutorSession $session): ?string
    {
        if (! $session->isGroup()) {
            return null;
        }

        return $session->participants()->where('learner_id', $user->id)->value('status');
    }

    /** @return list<string> */
    public static function seatStatesThatCount(): array
    {
        return [SeatStatus::Confirmed->value, SeatStatus::Attended->value];
    }
}
