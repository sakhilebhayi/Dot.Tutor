<?php

namespace App\Domain\Comms\Listeners;

use App\Domain\Comms\CommsMessage;
use App\Domain\Comms\Messenger;
use App\Domain\Marketplace\Models\TutorSessionEvent;
use App\Domain\Marketplace\Models\TutorSessionParticipant;
use App\Domain\Money\Money;
use App\Models\TutorSession;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns the marketplace's append-only booking history (tutor_session_events, written by its state machine on every
 * change) into notifications. A thin listener: it only reads the event row after the transaction that wrote it has
 * committed (a refused or rolled-back change notifies nobody), and it never changes the booking.
 *
 * Who hears what (docs/R47_COMMS.md section 2): the other party to each change, never the person who made it; the
 * guardians of a minor learner as well; tutors see a minor learner's first name only.
 */
final class BookingEventsObserver
{
    public bool $afterCommit = true;

    public function __construct(private readonly Messenger $m) {}

    public function created(TutorSessionEvent $event): void
    {
        try {
            $session = TutorSession::query()->with(['tutorProfile.user', 'student', 'subject'])->find($event->tutor_session_id);

            if ($session === null) {
                return;
            }

            if ($event->participant_id !== null) {
                $this->seatEvent($event, $session);

                return;
            }

            $this->sessionEvent($event, $session);
        } catch (Throwable $e) {
            Log::warning('Comms could not notify a booking change.', ['event_id' => $event->id, 'error' => class_basename($e)]);
        }
    }

    private function sessionEvent(TutorSessionEvent $event, TutorSession $session): void
    {
        $tutor = $session->tutorProfile?->user;
        $learner = $session->isGroup() ? null : $session->student;
        $key = 'booking-event:'.$event->id;
        $collapse = 'booking-'.$session->id;
        $bookings = route('marketplace.bookings', absolute: false);
        $what = $this->m->lessonWhat($session);
        $actor = $event->actor_id;
        $ics = ['session_id' => $session->id, 'ics_session_id' => $session->id];
        $ids = ['session_id' => $session->id];

        switch ($event->event) {
            case 'requested':
            case 'guardian_approved':
                if ($session->status !== 'pending' || $tutor === null || $learner === null) {
                    return;
                }
                $this->m->send(new CommsMessage($tutor, 'lesson_requested', $key.':tutor', 'New lesson request',
                    $this->m->learnerName($learner).' asked for a '.$what.' on '.$this->m->when($session->starts_at, $tutor).'. Accept or decline it.',
                    $bookings, $collapse, $actor, data: $ids + ['action_label' => 'Review the request'], aboutMinor: $this->m->isMinor($learner)));
                if ($event->event === 'requested') {
                    // The learner made the request; only their guardians hear about it (a teen's booking may not need approval).
                    $this->m->learnerAndGuardians($learner, $this->learnerMessage($learner, 'lesson_requested', $key, '', '', $bookings, $collapse, $actor, $ids),
                        fn (User $g) => [$this->m->firstName($learner).' asked for a lesson',
                            $this->m->firstName($learner).' asked '.$tutor->name.' for a '.$what.' on '.$this->m->when($session->starts_at, $g).'.'], toLearner: false);
                }

                return;

            case 'held_for_guardian':
                if ($learner === null) {
                    return;
                }
                // The guardian's own approval request comes from the safety domain; here the learner is told why it waits.
                $this->m->send(new CommsMessage($learner, 'lesson_awaiting_guardian', $key.':learner', 'Waiting for your parent or guardian',
                    'Your request for a '.$what.' on '.$this->m->when($session->starts_at, $learner).' goes to the tutor once your parent or guardian approves it.',
                    $bookings, $collapse, null, data: $ids, aboutMinor: true));

                return;

            case 'accepted':
                if ($learner === null || $tutor === null) {
                    return;
                }
                if ($event->to_state === 'awaiting_payment') {
                    $deadline = $session->hold_expires_at ? ' by '.$this->m->when($session->hold_expires_at, $learner) : '';
                    $this->m->learnerAndGuardians($learner,
                        $this->learnerMessage($learner, 'lesson_accepted', $key, $tutor->name.' accepted your lesson request',
                            'Pay'.$deadline.' to confirm your '.$what.' on '.$this->m->when($session->starts_at, $learner).'. Until then the time is held for you.',
                            $bookings, $collapse, $actor, $ids + ['action_label' => 'Pay to confirm']),
                        fn (User $g) => [$tutor->name.' accepted '.$this->m->firstName($learner).'\'s lesson request',
                            'The '.$what.' on '.$this->m->when($session->starts_at, $g).' is confirmed once it is paid'.$deadline.'.']);

                    return;
                }
                $this->confirmed($session, $learner, $tutor, $key, $collapse, $actor, $what, $bookings, $ics, notifyTutor: false);

                return;

            case 'paid':
                if ($learner === null || $tutor === null) {
                    return;
                }
                $this->confirmed($session, $learner, $tutor, $key, $collapse, $actor, $what, $bookings, $ics, notifyTutor: true);

                return;

            case 'declined':
                if ($learner === null || $tutor === null) {
                    return;
                }
                $reason = trim((string) $event->reason) !== '' ? ' Their reason: '.trim((string) $event->reason) : '';
                $this->m->learnerAndGuardians($learner,
                    $this->learnerMessage($learner, 'lesson_declined', $key, $tutor->name.' declined your lesson request',
                        'Your request for a '.$what.' on '.$this->m->when($session->starts_at, $learner).' was declined.'.$reason.' You can ask another tutor.',
                        route('marketplace.find', absolute: false), $collapse, $actor, $ids),
                    fn (User $g) => [$tutor->name.' declined '.$this->m->firstName($learner).'\'s lesson request',
                        'The '.$what.' asked for on '.$this->m->when($session->starts_at, $g).' will not take place.'.$reason]);

                return;

            case 'cancelled':
            case 'dispute_resolved':
                if ($event->event === 'dispute_resolved' && $event->to_state !== 'cancelled') {
                    $this->disputeResolved($session, $learner, $tutor, $key, $collapse, $actor, $what, $bookings, $ids, (array) $event->context);

                    return;
                }
                $this->cancelled($event, $session, $learner, $tutor, $key, $collapse, $what, $bookings);

                return;

            case 'expired':
                $this->expired($event, $session, $learner, $tutor, $key, $collapse, $what, $bookings, $ids);

                return;

            case 'completed':
                if ($learner === null || $tutor === null) {
                    return; // group seats are told through their own attendance below
                }
                $this->m->learnerAndGuardians($learner,
                    $this->learnerMessage($learner, 'lesson_review_invitation', $key, 'How was your lesson with '.$tutor->name.'?',
                        'Your '.$what.' is marked as done. A short review helps other learners choose, and only people who took a lesson can leave one.',
                        $bookings, $collapse, $actor, $ids + ['action_label' => 'Leave a review']),
                    fn (User $g) => [$this->m->firstName($learner).'\'s lesson took place', 'The '.$what.' with '.$tutor->name.' on '.$this->m->when($session->starts_at, $g).' is marked as done.']);
                if ($actor !== null && $actor === $learner->id) {
                    $this->m->send(new CommsMessage($tutor, 'lesson_completed', $key.':tutor', 'Lesson marked as done',
                        $this->m->learnerName($learner).' marked the '.$what.' on '.$this->m->when($session->starts_at, $tutor).' as done.',
                        $bookings, $collapse, $actor, data: $ids, aboutMinor: $this->m->isMinor($learner)));
                }
                if ($session->isGroup()) {
                    return;
                }

                return;

            case 'no_show':
                if ($learner === null || $tutor === null) {
                    return;
                }
                $this->m->learnerAndGuardians($learner,
                    $this->learnerMessage($learner, 'lesson_changed', $key, 'Lesson marked as missed',
                        $tutor->name.' recorded that you did not attend the '.$what.' on '.$this->m->when($session->starts_at, $learner).'. If that is wrong, you can raise a problem within 7 days.',
                        $bookings, $collapse, $actor, $ids),
                    fn (User $g) => [$this->m->firstName($learner).'\'s lesson was marked as missed',
                        $tutor->name.' recorded that '.$this->m->firstName($learner).' did not attend the '.$what.' on '.$this->m->when($session->starts_at, $g).'.']);

                return;

            case 'disputed':
                if ($learner === null || $tutor === null) {
                    return;
                }
                if ($actor === $learner->id) {
                    $this->m->send(new CommsMessage($tutor, 'lesson_changed', $key.':tutor', 'A learner raised a problem with a lesson',
                        $this->m->learnerName($learner).' raised a problem with the '.$what.' on '.$this->m->when($session->starts_at, $tutor).'. Dot.Tutor will review it.',
                        $bookings, $collapse, $actor, data: $ids, aboutMinor: $this->m->isMinor($learner)));
                } else {
                    $this->m->learnerAndGuardians($learner,
                        $this->learnerMessage($learner, 'lesson_changed', $key, $tutor->name.' raised a problem with a lesson',
                            'Your tutor raised a problem with the '.$what.' on '.$this->m->when($session->starts_at, $learner).'. Dot.Tutor will review it.',
                            $bookings, $collapse, $actor, $ids),
                        fn (User $g) => [$tutor->name.' raised a problem with '.$this->m->firstName($learner).'\'s lesson',
                            'Dot.Tutor will review the '.$what.' on '.$this->m->when($session->starts_at, $g).'.']);
                }

                return;

            default:
                return; // started, scheduled: no message needed
        }
    }

    /** @param array<string, scalar|null> $ics */
    private function confirmed(TutorSession $session, User $learner, User $tutor, string $key, string $collapse, ?int $actor, string $what, string $bookings, array $ics, bool $notifyTutor): void
    {
        $expires = ['expires_at' => $session->starts_at->toIso8601String()];
        $this->m->learnerAndGuardians($learner,
            $this->learnerMessage($learner, 'lesson_confirmed', $key, 'Lesson confirmed with '.$tutor->name,
                'Your '.$what.' is on '.$this->m->when($session->starts_at, $learner).' ('.(int) $session->duration_minutes.' minutes). Add it to your calendar so you do not miss it.',
                $bookings, $collapse, $actor, $ics + $expires + ['action_label' => 'See your bookings']),
            fn (User $g) => [$this->m->firstName($learner).'\'s lesson is confirmed',
                'The '.$what.' with '.$tutor->name.' is on '.$this->m->when($session->starts_at, $g).' ('.(int) $session->duration_minutes.' minutes).']);

        if ($notifyTutor) {
            $this->m->send(new CommsMessage($tutor, 'lesson_confirmed', $key.':tutor', 'Lesson confirmed and paid',
                'Your '.$what.' with '.$this->m->learnerName($learner).' on '.$this->m->when($session->starts_at, $tutor).' is paid and confirmed.',
                $bookings, $collapse, $actor, data: $ics + $expires, aboutMinor: $this->m->isMinor($learner)));
        }
    }

    private function cancelled(TutorSessionEvent $event, TutorSession $session, ?User $learner, ?User $tutor, string $key, string $collapse, string $what, string $bookings): void
    {
        if ($tutor === null) {
            return;
        }

        $context = (array) $event->context;
        $refund = (int) ($context['refund_due_minor'] ?? 0);
        $refundText = $refund > 0 && $session->payment_status === 'paid'
            ? ' A refund of '.Money::of($refund, $session->currency ?: null)->format().' is due to you; you will be told when it is processed.'
            : '';
        $reason = trim((string) $event->reason) !== '' ? ' Reason given: '.trim((string) $event->reason).'.' : '';
        $byOperator = $event->event === 'dispute_resolved';
        $ics = ['session_id' => $session->id, 'ics_session_id' => $session->id];

        if ($learner === null) {
            return; // a group lesson's seats are told one by one (seat_cancelled)
        }

        $byTutor = $event->actor_id !== null && $event->actor_id === $tutor->id;
        $byLearner = $event->actor_id !== null && $event->actor_id === $learner->id;

        if (! $byLearner) {
            $who = $byOperator ? 'Dot.Tutor' : ($byTutor ? $tutor->name : 'Dot.Tutor');
            $this->m->learnerAndGuardians($learner,
                $this->learnerMessage($learner, 'lesson_cancelled', $key, 'Lesson cancelled',
                    $who.' cancelled your '.$what.' on '.$this->m->when($session->starts_at, $learner).'.'.$reason.$refundText,
                    $bookings, $collapse, $event->actor_id, $ics),
                fn (User $g) => [$this->m->firstName($learner).'\'s lesson was cancelled',
                    $who.' cancelled the '.$what.' with '.$tutor->name.' on '.$this->m->when($session->starts_at, $g).'.'.$reason]);
        } else {
            $this->m->learnerAndGuardians($learner, $this->learnerMessage($learner, 'lesson_cancelled', $key, '', '', $bookings, $collapse, $event->actor_id, $ics),
                fn (User $g) => [$this->m->firstName($learner).' cancelled a lesson',
                    $this->m->firstName($learner).' cancelled the '.$what.' with '.$tutor->name.' on '.$this->m->when($session->starts_at, $g).'.'], toLearner: false);
        }

        if (! $byTutor) {
            $this->m->send(new CommsMessage($tutor, 'lesson_cancelled', $key.':tutor', 'Lesson cancelled',
                ($byLearner ? $this->m->learnerName($learner) : 'Dot.Tutor').' cancelled the '.$what.' on '.$this->m->when($session->starts_at, $tutor).'.'.$reason,
                $bookings, $collapse, $event->actor_id, data: $ics, aboutMinor: $this->m->isMinor($learner)));
        }
    }

    /** @param array<string, scalar|null> $ids */
    private function expired(TutorSessionEvent $event, TutorSession $session, ?User $learner, ?User $tutor, string $key, string $collapse, string $what, string $bookings, array $ids): void
    {
        if ($learner === null || $tutor === null) {
            return;
        }

        $why = match ($event->from_state) {
            'awaiting_payment' => 'It was not paid in time, so the time was released.',
            'awaiting_guardian' => 'No parent or guardian decided before the lesson time.',
            default => 'The tutor did not answer before the lesson time.',
        };

        $this->m->learnerAndGuardians($learner,
            $this->learnerMessage($learner, 'lesson_expired', $key, 'Lesson request lapsed',
                'Your request for a '.$what.' on '.$this->m->when($session->starts_at, $learner).' lapsed. '.$why, $bookings, $collapse, null, $ids),
            fn (User $g) => [$this->m->firstName($learner).'\'s lesson request lapsed', 'The '.$what.' with '.$tutor->name.' will not take place. '.$why]);

        if ($event->from_state !== 'awaiting_guardian') {
            $this->m->send(new CommsMessage($tutor, 'lesson_expired', $key.':tutor', 'Lesson request lapsed',
                'The '.$what.' with '.$this->m->learnerName($learner).' on '.$this->m->when($session->starts_at, $tutor).' lapsed. '.$why,
                $bookings, $collapse, null, data: $ids, aboutMinor: $this->m->isMinor($learner)));
        }
    }

    /**
     * @param  array<string, scalar|null>  $ids
     * @param  array<string, mixed>  $context
     */
    private function disputeResolved(TutorSession $session, ?User $learner, ?User $tutor, string $key, string $collapse, ?int $actor, string $what, string $bookings, array $ids, array $context): void
    {
        if ($learner === null || $tutor === null) {
            return;
        }

        $text = 'Dot.Tutor reviewed the problem raised about the '.$what.' and decided the lesson stands.';
        $this->m->learnerAndGuardians($learner, $this->learnerMessage($learner, 'lesson_changed', $key, 'Lesson review finished', $text, $bookings, $collapse, $actor, $ids),
            fn (User $g) => [$this->m->firstName($learner).'\'s lesson review finished', $text]);
        $this->m->send(new CommsMessage($tutor, 'lesson_changed', $key.':tutor', 'Lesson review finished', $text, $bookings, $collapse, $actor,
            data: $ids, aboutMinor: $this->m->isMinor($learner)));
    }

    private function seatEvent(TutorSessionEvent $event, TutorSession $session): void
    {
        $seat = TutorSessionParticipant::query()->find($event->participant_id);
        $learner = $seat ? User::find($seat->learner_id) : null;
        $tutor = $session->tutorProfile?->user;

        if ($learner === null || $tutor === null) {
            return;
        }

        $key = 'booking-event:'.$event->id;
        $collapse = 'seat-'.$seat->id;
        $bookings = route('marketplace.bookings', absolute: false);
        $what = $this->m->lessonWhat($session);
        $when = fn (User $u) => $this->m->when($session->starts_at, $u);
        $ids = ['session_id' => $session->id];

        switch ($event->event) {
            case 'seat_paid':
                $this->m->learnerAndGuardians($learner,
                    $this->learnerMessage($learner, 'seat_confirmed', $key, 'Your place is confirmed',
                        'Your place in the '.$what.' with '.$tutor->name.' on '.$when($learner).' is paid and confirmed.',
                        $bookings, $collapse, null, $ids + ['ics_session_id' => $session->id, 'expires_at' => $session->starts_at->toIso8601String()]),
                    fn (User $g) => [$this->m->firstName($learner).'\'s place is confirmed', 'The '.$what.' with '.$tutor->name.' is on '.$when($g).'.']);

                return;

            case 'seat_cancelled':
            case 'seat_expired':
                if ($event->actor_id !== null && $event->actor_id === $learner->id) {
                    return;
                }
                $why = $event->event === 'seat_expired' ? ' '.trim((string) $event->reason) : (trim((string) $event->reason) !== '' ? ' '.trim((string) $event->reason) : '');
                $this->m->learnerAndGuardians($learner,
                    $this->learnerMessage($learner, 'seat_cancelled', $key, 'Your place was cancelled',
                        'Your place in the '.$what.' on '.$when($learner).' was cancelled.'.$why, $bookings, $collapse, $event->actor_id, $ids),
                    fn (User $g) => [$this->m->firstName($learner).'\'s place was cancelled', 'The place in the '.$what.' on '.$when($g).' was cancelled.'.$why]);

                return;

            case 'payment_after_release':
                $this->m->learnerAndGuardians($learner,
                    $this->learnerMessage($learner, 'lesson_changed', $key, 'Payment arrived after your place was released',
                        'Your payment for the '.$what.' arrived after the place was released. A refund is due to you; you will be told when it is processed.',
                        $bookings, $collapse, null, $ids),
                    fn (User $g) => [$this->m->firstName($learner).'\'s payment arrived late', 'A refund is due for the '.$what.' on '.$when($g).'.']);

                return;

            default:
                return;
        }
    }

    /** @param array<string, scalar|null> $data */
    private function learnerMessage(User $learner, string $type, string $key, string $title, string $body, ?string $url, string $collapse, ?int $actor, array $data): CommsMessage
    {
        return new CommsMessage($learner, $type, $key.':learner', $title, $body, $url, $collapse, $actor, data: $data);
    }
}
