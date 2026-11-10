<?php

namespace App\Domain\Learning\Services;

use App\Domain\Learning\Contracts\ProgressFeed;
use App\Domain\Learning\Models\Attempt;
use App\Domain\Learning\Models\AttemptAnswer;
use App\Domain\Learning\Models\Certificate;
use App\Domain\Learning\Models\LessonProgress;
use App\Models\User;
use Illuminate\Support\Carbon;

/** The ProgressFeed seam, read from recorded activity. */
class DatabaseProgressFeed implements ProgressFeed
{
    public function __construct(private MasteryCalculator $mastery, private InsightWriter $writer) {}

    public function recentEvents(User $user, int $limit = 20): array
    {
        $events = [];

        $completions = LessonProgress::query()
            ->join('learning_enrolments', 'learning_enrolments.id', '=', 'learning_lesson_progress.enrolment_id')
            ->join('learning_courses', 'learning_courses.id', '=', 'learning_enrolments.course_id')
            ->leftJoin('learning_lessons', 'learning_lessons.id', '=', 'learning_lesson_progress.lesson_id')
            ->where('learning_enrolments.user_id', $user->id)
            ->whereNotNull('learning_lesson_progress.completed_at')
            ->orderByDesc('learning_lesson_progress.completed_at')
            ->limit($limit)
            ->get(['learning_lesson_progress.completed_at', 'learning_lesson_progress.lesson_id', 'learning_courses.id as course_id', 'learning_courses.title as course_title', 'learning_lessons.title as lesson_title']);
        foreach ($completions as $row) {
            $events[] = [
                'type' => 'lesson_completed',
                'at' => $row->completed_at->toIso8601String(),
                'title' => 'Completed "'.($row->lesson_title ?? 'a lesson').'" in '.$row->course_title,
                'course_id' => (int) $row->course_id,
                'assessment_id' => null,
                'detail' => ['lesson_id' => (int) $row->lesson_id],
            ];
        }

        foreach (Attempt::query()->with('assessment')->where('user_id', $user->id)->where('status', 'submitted')
            ->orderByDesc('submitted_at')->limit($limit)->get() as $attempt) {
            $events[] = [
                'type' => 'attempt_submitted',
                'at' => $attempt->submitted_at->toIso8601String(),
                'title' => "Submitted {$attempt->assessment->title}: {$attempt->score}/{$attempt->max_score}",
                'course_id' => $attempt->assessment->course_id,
                'assessment_id' => $attempt->assessment_id,
                'detail' => ['percent' => $attempt->percent, 'passed' => $attempt->passed, 'late' => $attempt->late, 'kind' => $attempt->assessment->kind->value],
            ];
        }

        foreach (Certificate::query()->where('user_id', $user->id)->whereNull('revoked_at')->orderByDesc('issued_at')->limit($limit)->get() as $certificate) {
            $events[] = [
                'type' => 'certificate_issued',
                'at' => $certificate->issued_at->toIso8601String(),
                'title' => 'Certificate for '.$certificate->course_title,
                'course_id' => $certificate->course_id,
                'assessment_id' => null,
                'detail' => ['code' => $certificate->code],
            ];
        }

        usort($events, fn ($a, $b) => strcmp($b['at'], $a['at']));

        return array_slice($events, 0, $limit);
    }

    public function mastery(User $user): array
    {
        return array_map(fn ($row) => array_intersect_key($row, array_flip(['subject_id', 'strand', 'topic', 'answered', 'recent_accuracy_percent', 'level', 'trend'])), $this->mastery->forUser($user));
    }

    public function insights(User $user): array
    {
        $rows = $this->mastery->forUser($user);
        $answers = AttemptAnswer::query()->where('user_id', $user->id)->where('counts_for_mastery', true);

        return [
            'label' => 'Computed from your answers. Not AI-generated, and not an official exam result.',
            'based_on' => [
                'answers' => (clone $answers)->count(),
                'attempts' => (clone $answers)->distinct()->count('attempt_id'),
                'last_answer_at' => optional((clone $answers)->max('answered_at'), fn ($at) => Carbon::parse($at)->toIso8601String()),
            ],
            'sentences' => $this->writer->sentences($rows),
        ];
    }
}
