<?php

namespace App\Domain\Comms\Listeners;

use App\Domain\Classroom\Events\ClassroomOpened;
use App\Domain\Classroom\Events\LessonStartingSoon;
use App\Domain\Classroom\Events\LessonSummaryPublished;
use App\Domain\Classroom\Models\Classroom;
use App\Domain\Classroom\Services\ClassroomAccess;
use App\Domain\Comms\CommsMessage;
use App\Domain\Comms\Messenger;
use App\Enums\SessionStatus;
use App\Models\TutorSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The live classroom's moments (D-812: the classroom sends nothing itself). Starting soon (classroom:sweep, about 15
 * minutes before) and the room opened are in-the-moment notices for the people in the lesson, with the classroom
 * link; they share the booking's collapse key with the 1 h reminder, so the badge counts the lesson once and a phone
 * shows the latest. Guardians get no copy of these (as with the 1 h reminder). The published summary goes to the
 * learners and a minor's guardians, linking to the after-lesson page. ClassroomEnded and FollowUpAssigned are
 * deliberately not listened to (docs/R47_COMMS.md section 2: the lesson-completed notice and the Assignment
 * observer's assignment_set already tell people). The actor is never told about their own action.
 */
final class ClassroomNotifications
{
    public function __construct(private readonly Messenger $m, private readonly ClassroomAccess $access) {}

    public function lessonStartingSoon(LessonStartingSoon $event): void
    {
        try {
            [$session, $tutor, $learners] = $this->lesson($event->classroom);
            if ($session === null) {
                return;
            }

            $key = 'lesson:'.$session->id.':starting:'.$session->starts_at->timestamp;
            $what = $this->m->lessonWhat($session);
            $opens = CarbonImmutable::instance($this->access->opensAt($session));
            $open = fn (User $reader) => $opens->isPast() ? 'The classroom is open.' : 'The classroom opens at '.$this->time($opens, $reader).'.';
            $data = $this->data($session, 'Open the classroom');
            $url = route('classroom.show', $session, false);

            $this->m->send(new CommsMessage($tutor, 'lesson_starting', $key.':tutor', ucfirst($what).' starts soon',
                'Your '.$what.' with '.$this->learnersFor($learners).' starts at '.$this->time($session->starts_at, $tutor).'. '.$open($tutor),
                $url, 'booking-'.$session->id, data: $data, aboutMinor: $this->anyMinor($learners)));

            foreach ($learners as $learner) {
                $this->m->learnerAndGuardians($learner, new CommsMessage($learner, 'lesson_starting', $key.':learner-'.$learner->id,
                    ucfirst($what).' with '.$tutor->name.' starts soon',
                    'Your '.$what.' starts at '.$this->time($session->starts_at, $learner).'. '.$open($learner),
                    $url, 'booking-'.$session->id, data: $data), null);
            }
        } catch (Throwable $e) {
            Log::warning('Comms could not notify a lesson starting soon.', ['classroom_id' => $event->classroom->id, 'error' => class_basename($e)]);
        }
    }

    public function classroomOpened(ClassroomOpened $event): void
    {
        try {
            [$session, $tutor, $learners] = $this->lesson($event->classroom);
            if ($session === null) {
                return;
            }

            $by = (int) ($event->context['by'] ?? 0);
            $classroom = $event->classroom;
            $key = 'classroom:'.$classroom->id.':opened:'.($classroom->started_at?->timestamp ?? $session->starts_at->timestamp);
            $what = $this->m->lessonWhat($session);
            $data = $this->data($session, 'Join the lesson');
            $url = route('classroom.show', $session, false);

            if ($by !== $tutor->id) {
                $opener = $learners->firstWhere('id', $by);
                $this->m->send(new CommsMessage($tutor, 'classroom_open', $key.':tutor',
                    ($opener ? $this->m->learnerName($opener) : 'A parent or guardian').' is waiting in the classroom',
                    'Your '.$what.' is due to start at '.$this->time($session->starts_at, $tutor).'.',
                    $url, 'booking-'.$session->id, $by ?: null, data: $data, aboutMinor: $this->anyMinor($learners)));
            }

            foreach ($learners as $learner) {
                if ($learner->id === $by) {
                    continue;
                }
                $this->m->learnerAndGuardians($learner, new CommsMessage($learner, 'classroom_open', $key.':learner-'.$learner->id,
                    $by === $tutor->id ? $tutor->name.' is in the classroom' : 'The classroom is open',
                    'Your '.$what.' with '.$tutor->name.' is starting. Join when you are ready.',
                    $url, 'booking-'.$session->id, $by ?: null, data: $data), null);
            }
        } catch (Throwable $e) {
            Log::warning('Comms could not notify an opened classroom.', ['classroom_id' => $event->classroom->id, 'error' => class_basename($e)]);
        }
    }

    public function lessonSummaryPublished(LessonSummaryPublished $event): void
    {
        try {
            [$session, $tutor, $learners] = $this->lesson($event->classroom, false);
            if ($session === null) {
                return;
            }

            $classroom = $event->classroom;
            $ids = array_map('intval', (array) ($event->context['learner_ids'] ?? []));
            if ($ids !== []) {
                $learners = $learners->filter(fn (User $l) => in_array($l->id, $ids, true))->values();
            }
            $what = $this->m->lessonWhat($session);
            $url = route('classroom.after', $session, false);

            foreach ($learners as $learner) {
                if ($learner->id === $tutor->id) {
                    continue;
                }
                $this->m->learnerAndGuardians($learner,
                    new CommsMessage($learner, 'lesson_summary', 'classroom:'.$classroom->id.':summary:learner-'.$learner->id,
                        'Summary of your '.$what, $tutor->name.' wrote up the '.$what.' of '.$this->m->when($session->starts_at, $learner).': what you covered and what to practise.',
                        $url, 'booking-'.$session->id, $tutor->id, data: ['session_id' => $session->id, 'action_label' => 'Read the summary']),
                    fn (User $g) => [$this->m->firstName($learner).'\'s lesson summary is ready',
                        $tutor->name.' wrote up the '.$what.' of '.$this->m->when($session->starts_at, $g).'.'],
                    guardianUrl: $url);
            }
        } catch (Throwable $e) {
            Log::warning('Comms could not notify a lesson summary.', ['classroom_id' => $event->classroom->id, 'error' => class_basename($e)]);
        }
    }

    /**
     * The lesson, its tutor and its booked learners; nulls when the lesson is gone or (for the in-the-moment notices)
     * no longer about to happen.
     *
     * @return array{0: ?TutorSession, 1: ?User, 2: Collection<int, User>}
     */
    private function lesson(Classroom $classroom, bool $mustBeUpcoming = true): array
    {
        $session = TutorSession::query()->with(['tutorProfile.user', 'subject'])->find($classroom->tutor_session_id);
        $tutor = $session?->tutorProfile?->user;
        if ($session === null || $tutor === null) {
            return [null, null, collect()];
        }
        if ($mustBeUpcoming && ! in_array($session->statusEnum(), [SessionStatus::Confirmed, SessionStatus::InProgress], true)) {
            return [null, null, collect()];
        }
        $classroom->setRelation('session', $session);

        return [$session, $tutor, User::query()->whereIn('id', $classroom->learnerIds())->orderBy('id')->get()];
    }

    /** @param Collection<int, User> $learners */
    private function learnersFor(Collection $learners): string
    {
        if ($learners->count() === 1) {
            return $this->m->learnerName($learners->first());
        }

        return $learners->count().' learners';
    }

    /** @param Collection<int, User> $learners */
    private function anyMinor(Collection $learners): bool
    {
        return $learners->contains(fn (User $l) => $this->m->isMinor($l));
    }

    /** @return array<string, scalar> */
    private function data(TutorSession $session, string $label): array
    {
        // Pointless once the lesson is over: a copy held by quiet hours or retries past the end is not sent.
        return ['session_id' => $session->id, 'expires_at' => $session->endsAt()->toIso8601String(), 'action_label' => $label];
    }

    private function time(\DateTimeInterface $at, User $reader): string
    {
        $when = $this->m->when(CarbonImmutable::instance($at), $reader);

        return substr($when, strpos($when, ' at ') + 4);
    }
}
