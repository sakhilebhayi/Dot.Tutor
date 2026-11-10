<?php

namespace App\Domain\Comms;

use App\Domain\Learning\Models\Assignment;
use App\Domain\Learning\Models\Attempt;
use App\Domain\Marketplace\Models\TutorSessionEvent;
use App\Domain\Marketplace\Models\TutorSessionParticipant;
use App\Models\TutorSession;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Lesson reminders (a day and an hour before each confirmed lesson) and assignment due reminders, run by cron
 * (`comms:reminders`, every five minutes). Idempotent: each reminder's event key names the lesson, the lead time and
 * the start time, so a second run, an overlapping run or a retry sends nothing more, while a lesson moved to a new
 * time gets fresh reminders. A reminder is skipped when the lesson was confirmed so recently that the confirmation
 * itself was the message, and every reminder expires at the lesson's start (a push held by quiet hours past the
 * start is not sent).
 */
final class Reminders
{
    public function __construct(private readonly Messenger $m) {}

    /** @return array{lessons: int, assignments: int} messages written */
    public function run(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        return ['lessons' => $this->lessons($now), 'assignments' => $this->assignments($now)];
    }

    private function lessons(CarbonImmutable $now): int
    {
        $written = 0;
        $recent = (int) config('comms.reminders.skip_if_confirmed_within_minutes', 30);

        foreach ((array) config('comms.reminders.lesson', []) as $label => $rule) {
            $lead = (int) $rule['minutes'];
            $window = (int) $rule['window'];
            $from = $now->addMinutes($lead - $window);
            $to = $now->addMinutes($lead);

            TutorSession::query()->with(['tutorProfile.user', 'student', 'subject'])
                ->where('status', 'confirmed')
                ->where('starts_at', '>', $from)->where('starts_at', '<=', $to)
                ->orderBy('id')
                ->each(function (TutorSession $session) use ($label, $now, $recent, &$written) {
                    $confirmedAt = TutorSessionEvent::query()->where('tutor_session_id', $session->id)
                        ->where('to_state', 'confirmed')->orderByDesc('id')->value('created_at');
                    if ($confirmedAt !== null && CarbonImmutable::parse($confirmedAt)->gt($now->subMinutes($recent))) {
                        return;
                    }
                    $written += $this->remindSession($session, (string) $label);
                });
        }

        return $written;
    }

    private function remindSession(TutorSession $session, string $label): int
    {
        $tutor = $session->tutorProfile?->user;
        if ($tutor === null) {
            return 0;
        }

        $key = 'lesson:'.$session->id.':reminder:'.$label.':'.$session->starts_at->timestamp;
        $collapse = 'booking-'.$session->id;
        $what = $this->m->lessonWhat($session);
        $soon = $label === '1h' ? 'in about an hour' : 'tomorrow';
        $title = fn (string $who) => ucfirst($what).' '.($label === '1h' ? 'in an hour' : 'tomorrow').($who !== '' ? ' with '.$who : '');
        $data = ['session_id' => $session->id, 'ics_session_id' => $session->id, 'expires_at' => $session->starts_at->toIso8601String(), 'action_label' => 'See your bookings'];
        $url = route('marketplace.bookings', absolute: false);
        $count = 0;

        $learners = $session->isGroup()
            ? User::query()->whereIn('id', TutorSessionParticipant::query()->where('tutor_session_id', $session->id)->where('status', 'confirmed')->select('learner_id'))->orderBy('id')->get()
            : collect($session->student ? [$session->student] : []);

        $tutorBody = $session->isGroup()
            ? 'Your '.$what.' with '.$learners->count().' '.($learners->count() === 1 ? 'learner' : 'learners').' starts '.$this->m->when($session->starts_at, $tutor).'.'
            : 'Your '.$what.' with '.$this->m->learnerName($learners->first()).' starts '.$this->m->when($session->starts_at, $tutor).'.';
        $count += $this->m->send(new CommsMessage($tutor, 'lesson_reminder', $key.':tutor', $title(''), $tutorBody, $url, $collapse,
            data: $data, aboutMinor: $learners->contains(fn (User $l) => $this->m->isMinor($l)))) ? 1 : 0;

        foreach ($learners as $learner) {
            $message = new CommsMessage($learner, 'lesson_reminder', $key.':learner-'.$learner->id, $title($tutor->name),
                'Your '.$what.' with '.$tutor->name.' is '.$soon.': '.$this->m->when($session->starts_at, $learner).'.', $url, $collapse, data: $data);
            // Guardians get the day-before reminder only; the hour-before one is for the learner.
            $this->m->learnerAndGuardians($learner, $message, $label === '24h'
                ? fn (User $g) => [$this->m->firstName($learner).' has a lesson '.$soon, 'The '.$what.' with '.$tutor->name.' is on '.$this->m->when($session->starts_at, $g).'.']
                : null);
            $count++;
        }

        return $count;
    }

    private function assignments(CarbonImmutable $now): int
    {
        $lead = (int) config('comms.reminders.assignment_due_minutes', 1440);
        $window = (int) config('comms.reminders.assignment_window', 180);
        $written = 0;

        Assignment::query()->with('assessment')
            ->whereNotNull('due_at')
            ->where('due_at', '>', $now->addMinutes($lead - $window))->where('due_at', '<=', $now->addMinutes($lead))
            ->orderBy('id')
            ->each(function (Assignment $assignment) use (&$written) {
                $learner = User::find($assignment->learner_id);
                if ($learner === null || $assignment->assessment === null) {
                    return;
                }
                $done = Attempt::query()->where('assessment_id', $assignment->assessment_id)->where('user_id', $learner->id)->whereNotNull('submitted_at')->exists();
                if ($done) {
                    return;
                }
                $written += $this->m->send(new CommsMessage($learner, 'assignment_due', 'assignment:'.$assignment->id.':due:'.$assignment->due_at->timestamp,
                    'Due tomorrow: '.$assignment->assessment->title,
                    '"'.$assignment->assessment->title.'" is due '.$this->m->when($assignment->due_at, $learner).' and you have not handed it in yet.',
                    route('learning.assessments.show', $assignment->assessment, false), 'assignment-'.$assignment->id,
                    data: ['assessment_id' => $assignment->assessment_id, 'expires_at' => $assignment->due_at->toIso8601String(), 'action_label' => 'Open the work'])) ? 1 : 0;
            });

        return $written;
    }
}
