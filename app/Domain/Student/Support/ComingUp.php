<?php

namespace App\Domain\Student\Support;

use App\Domain\Learning\Models\Enrolment;
use App\Domain\Marketplace\Models\TutorSessionParticipant;
use App\Models\TutorSession;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The learner home's one "Coming up" list (R-51). Five collections the controller already loads (booked lessons, group
 * seats, work due, courses in progress, finished lessons to review) become one list of rows of the same shape, so the
 * page is a single column of things to do. Nothing is queried here and nothing is invented: a row exists only for a
 * record the learner has.
 *
 * Order: what needs the learner first (a lesson to pay for, a lesson to review, work past its due date), then the rest
 * by date, soonest first; courses carry no date and follow the dated rows. The finished lesson the learner may book
 * again sits last, outside the cap.
 *
 * Row: kind (lesson|seat|work|course|review|last), title, meta, href, tag (text, icon, tone) or null, action (label,
 * href) or null, at (when it happens) or null, needs_you.
 *
 * @phpstan-type Row array{kind: string, title: string, meta: string, href: string, tag: array{text: string, icon: string, tone: string}|null, action: array{label: string, href: string}|null, at: ?CarbonInterface, needs_you: bool}
 */
final class ComingUp
{
    /**
     * @param  Collection<int, TutorSession>  $lessons
     * @param  Collection<int, TutorSessionParticipant>  $seats
     * @param  list<array{assignment: mixed, title: string, due_at: ?CarbonInterface, overdue: bool}>  $work
     * @param  list<array{enrolment: Enrolment, title: string, summary: array<string, mixed>}>  $courses
     * @param  Collection<int, TutorSession>  $toReview
     * @param  array{lesson: TutorSession, summary: ?string}|null  $last
     * @return list<Row>
     */
    public static function rows(Collection $lessons, Collection $seats, array $work, array $courses, Collection $toReview, ?array $last = null, int $limit = 6): array
    {
        $rows = [];

        foreach ($lessons as $lesson) {
            $rows[] = self::lesson($lesson);
        }
        foreach ($seats as $seat) {
            $rows[] = self::seat($seat);
        }
        foreach ($work as $item) {
            $rows[] = self::work($item);
        }
        foreach ($courses as $course) {
            $rows[] = self::course($course);
        }
        foreach ($toReview as $lesson) {
            $rows[] = self::review($lesson);
        }

        // A stable sort: needs-you first, then dated rows by time, then undated rows, each group in the order given.
        $indexed = array_map(fn (array $row, int $i) => [$i, $row], $rows, array_keys($rows));
        usort($indexed, function (array $a, array $b): int {
            [$ai, $ar] = $a;
            [$bi, $br] = $b;

            return ((int) $br['needs_you'] <=> (int) $ar['needs_you'])
                ?: (($ar['at'] === null) <=> ($br['at'] === null))
                ?: ($ar['at']?->getTimestamp() <=> $br['at']?->getTimestamp())
                ?: ($ai <=> $bi);
        });
        $rows = array_slice(array_map(fn (array $pair) => $pair[1], $indexed), 0, max(0, $limit));

        if ($last !== null) {
            $rows[] = self::last($last);
        }

        return $rows;
    }

    /** @return Row */
    private static function lesson(TutorSession $lesson): array
    {
        return [
            'kind' => 'lesson',
            'title' => ($lesson->subject?->name ?? 'Lesson').' with '.$lesson->tutorProfile->displayName(),
            'meta' => Times::when($lesson->starts_at).' · '.$lesson->duration_minutes.' min · '.($lesson->delivery === 'in_person' ? 'In person' : 'Online'),
            'href' => route('learn.lessons.show', $lesson),
            'tag' => self::state((string) $lesson->status),
            'action' => null,
            'at' => $lesson->starts_at,
            'needs_you' => $lesson->status === 'awaiting_payment',
        ];
    }

    /** @return Row */
    private static function seat(TutorSessionParticipant $seat): array
    {
        $session = $seat->session;

        return [
            'kind' => 'seat',
            'title' => ($session->subject?->name ?? 'Group lesson').', group with '.$session->tutorProfile->displayName(),
            'meta' => Times::when($session->starts_at).' · your seat',
            'href' => route('learn.lessons.show', $session),
            'tag' => self::state((string) $seat->status),
            'action' => null,
            'at' => $session->starts_at,
            'needs_you' => $seat->status === 'awaiting_payment',
        ];
    }

    /**
     * @param  array{assignment: mixed, title: string, due_at: ?CarbonInterface, overdue: bool}  $item
     * @return Row
     */
    private static function work(array $item): array
    {
        $due = $item['due_at'];
        $meta = match (true) {
            $due === null => 'Set by your tutor · No due date',
            $item['overdue'] => 'Set by your tutor · Past its due date ('.Times::when($due).')',
            default => 'Set by your tutor · Due '.Times::relative($due),
        };

        return [
            'kind' => 'work',
            'title' => $item['title'],
            'meta' => $meta,
            'href' => route('learning.assessments.take', $item['assignment']->assessment_id),
            'tag' => $item['overdue']
                ? ['text' => 'Past its due date', 'icon' => 'circle-alert', 'tone' => 'danger']
                : ['text' => 'Work due', 'icon' => 'notebook-pen', 'tone' => 'warn'],
            'action' => null,
            'at' => $due,
            'needs_you' => $item['overdue'],
        ];
    }

    /**
     * @param  array{enrolment: Enrolment, title: string, summary: array<string, mixed>}  $course
     * @return Row
     */
    private static function course(array $course): array
    {
        $href = route('learning.learn', [$course['enrolment']->course_id, $course['summary']['resume_lesson_id']]);

        return [
            'kind' => 'course',
            'title' => $course['title'],
            'meta' => 'Course · '.$course['summary']['completed'].' of '.$course['summary']['total'].' lessons done',
            'href' => $href,
            'tag' => null,
            'action' => ['label' => 'Resume', 'href' => $href],
            'at' => null,
            'needs_you' => false,
        ];
    }

    /** @return Row */
    private static function review(TutorSession $lesson): array
    {
        return [
            'kind' => 'review',
            'title' => ($lesson->subject?->name ?? 'Lesson').' with '.$lesson->tutorProfile->displayName(),
            'meta' => 'How did it go? · '.Times::when($lesson->starts_at),
            'href' => route('learn.lessons.show', $lesson).'#review',
            'tag' => ['text' => 'Review', 'icon' => 'star', 'tone' => 'info'],
            'action' => ['label' => 'Review', 'href' => route('learn.lessons.show', $lesson).'#review'],
            'at' => $lesson->starts_at,
            'needs_you' => true,
        ];
    }

    /**
     * @param  array{lesson: TutorSession, summary: ?string}  $last
     * @return Row
     */
    private static function last(array $last): array
    {
        $lesson = $last['lesson'];
        $again = $lesson->tutor_offering_id && $lesson->offering?->is_active
            ? ['label' => 'Book again', 'href' => route('learn.book', $lesson->tutor_offering_id)]
            : ['label' => 'See the profile', 'href' => route('marketplace.tutors.show', $lesson->tutor_profile_id)];

        return [
            'kind' => 'last',
            'title' => 'Your last lesson: '.($lesson->subject?->name ?? 'Lesson').' with '.$lesson->tutorProfile->displayName(),
            'meta' => Times::stamp($lesson->starts_at).' · '.$lesson->duration_minutes.' min',
            'href' => route('learn.lessons.show', $lesson),
            'tag' => null,
            'action' => $again,
            'at' => null,
            'needs_you' => false,
        ];
    }

    /** @return array{text: string, icon: string, tone: string} */
    private static function state(string $status): array
    {
        [$text, $icon, $tone] = match ($status) {
            'awaiting_guardian' => ['Waiting for your guardian', 'hourglass', 'info'],
            'pending' => ['Waiting for the tutor', 'hourglass', 'info'],
            'awaiting_payment' => ['Pay to confirm', 'circle-alert', 'warn'],
            'confirmed' => ['Confirmed', 'circle-check', 'ok'],
            'in_progress' => ['Happening now', 'circle-dashed', 'ok'],
            default => [ucfirst(str_replace('_', ' ', $status)), 'info', 'neutral'],
        };

        return ['text' => $text, 'icon' => $icon, 'tone' => $tone];
    }
}
