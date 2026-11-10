<?php

namespace App\Domain\Learning\Services;

use App\Domain\Learning\Enums\AssessmentKind;
use App\Domain\Learning\Models\Assessment;
use App\Domain\Learning\Models\Assignment;
use App\Domain\Learning\Models\Attempt;
use App\Domain\Learning\Models\AttemptAnswer;
use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\Question;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Starting and submitting attempts. The answer key never leaves the server before submission; the deadline is the
 * server's clock (a 30-second grace covers network delay); answers after it are kept but flagged late and cannot pass.
 */
class AttemptService
{
    private const GRACE_SECONDS = 30;

    public function __construct(
        private Marker $marker,
        private CourseAccess $courseAccess,
        private ProgressService $progress,
    ) {}

    public function canAttempt(User $user, Assessment $assessment): bool
    {
        if ($assessment->isOwnedBy($user)) {
            return true;
        }
        if (Assignment::query()->where('assessment_id', $assessment->id)->where('learner_id', $user->id)->exists()) {
            return true;
        }
        if ($assessment->course_id !== null && ($course = Course::query()->find($assessment->course_id)) !== null) {
            return $this->courseAccess->activeEnrolment($user, $course) !== null;
        }

        return false;
    }

    public function start(User $user, Assessment $assessment): Attempt
    {
        return DB::transaction(function () use ($user, $assessment) {
            $open = Attempt::query()->where('assessment_id', $assessment->id)->where('user_id', $user->id)
                ->where('status', 'in_progress')->lockForUpdate()->first();
            if ($open !== null) {
                return $open;
            }
            if ($assessment->max_attempts !== null) {
                $used = Attempt::query()->where('assessment_id', $assessment->id)->where('user_id', $user->id)->count();
                if ($used >= $assessment->max_attempts) {
                    throw ValidationException::withMessages(['attempt' => 'You have used all your attempts for this assessment.']);
                }
            }
            $ids = $assessment->questions()->whereNull('learning_questions.retired_at')->pluck('learning_questions.id')
                ->map(fn ($id) => (int) $id)->all();
            if ($ids === []) {
                throw ValidationException::withMessages(['attempt' => 'This assessment has no questions yet.']);
            }
            if ($assessment->shuffle_questions) {
                shuffle($ids);
            }

            return Attempt::create([
                'assessment_id' => $assessment->id,
                'user_id' => $user->id,
                'question_order' => $ids,
                'status' => 'in_progress',
                'started_at' => now(),
                'deadline_at' => $assessment->time_limit_minutes ? now()->addMinutes($assessment->time_limit_minutes) : null,
            ]);
        });
    }

    /** @return list<array<string, mixed>> the questions as the learner sees them, in attempt order */
    public function questionsFor(Attempt $attempt): array
    {
        $questions = Question::query()->whereIn('id', $attempt->question_order)->get()->keyBy('id');

        return collect($attempt->question_order)->map(fn ($id) => $questions[$id]?->forLearner())->filter()->values()->all();
    }

    /** @param  array<int|string, mixed>  $responses  question id => response */
    public function submit(Attempt $attempt, array $responses): Attempt
    {
        $attempt = DB::transaction(function () use ($attempt, $responses) {
            $attempt = Attempt::query()->lockForUpdate()->findOrFail($attempt->id);
            if ($attempt->status !== 'in_progress') {
                throw ValidationException::withMessages(['attempt' => 'This attempt has already been submitted.']);
            }
            $assessment = $attempt->assessment;
            $late = $attempt->deadline_at !== null && now()->greaterThan($attempt->deadline_at->copy()->addSeconds(self::GRACE_SECONDS));
            $questions = Question::query()->with('bank')->whereIn('id', $attempt->question_order)->get()->keyBy('id');
            // The creator's own trial runs are not evidence of anyone's learning.
            $countsForMastery = ! $assessment->isOwnedBy($attempt->user);

            $score = 0;
            $max = 0;
            foreach ($attempt->question_order as $questionId) {
                $question = $questions[$questionId] ?? null;
                if ($question === null) {
                    continue;
                }
                $response = $responses[$questionId] ?? $responses[(string) $questionId] ?? null;
                $result = $this->marker->mark($question, $response);
                $score += $result['marks'];
                $max += $question->marks;
                AttemptAnswer::create([
                    'attempt_id' => $attempt->id,
                    'question_id' => $question->id,
                    'user_id' => $attempt->user_id,
                    'subject_id' => $question->bank->subject_id,
                    'strand' => $question->bank->strand,
                    'topic' => $question->topicLabel(),
                    'response' => $response === null ? null : ['value' => $response],
                    'is_correct' => $result['correct'],
                    'marks_awarded' => $result['marks'],
                    'marks_possible' => $question->marks,
                    'counts_for_mastery' => $countsForMastery,
                    'answered_at' => now(),
                ]);
            }
            $percent = $max === 0 ? 0 : intdiv($score * 100, $max);
            $attempt->forceFill([
                'status' => 'submitted',
                'submitted_at' => now(),
                'late' => $late,
                'score' => $score,
                'max_score' => $max,
                'percent' => $percent,
                'passed' => ! $late && $percent >= $assessment->pass_percent,
            ])->save();

            return $attempt;
        });

        $this->progress->markQuizLessons($attempt);

        return $attempt;
    }

    /**
     * The marked result. Correct answers and explanations appear only if the assessment reveals them.
     *
     * @return array<string, mixed>
     */
    public function result(Attempt $attempt): array
    {
        $assessment = $attempt->assessment;
        $reveal = $assessment->reveal_answers === 'after_submit' && $attempt->status === 'submitted';
        $answers = $attempt->answers()->with('question')->get()->keyBy('question_id');

        return [
            'attempt_id' => $attempt->id,
            'assessment' => ['id' => $assessment->id, 'title' => $assessment->title, 'kind' => $assessment->kind->value],
            'status' => $attempt->status,
            'late' => $attempt->late,
            'score' => $attempt->score,
            'max_score' => $attempt->max_score,
            'percent' => $attempt->percent,
            'passed' => $attempt->passed,
            'pass_percent' => $assessment->pass_percent,
            'result_kind' => $assessment->kind === AssessmentKind::Practice ? 'practice' : 'assessed',
            'label' => $assessment->kind === AssessmentKind::Practice
                ? 'Practice result, marked by Dot.Tutor from your answers.'
                : 'Assessment result, marked by Dot.Tutor against the answer key your tutor set.',
            'questions' => collect($attempt->question_order)->map(function ($id) use ($answers, $reveal) {
                $answer = $answers[$id] ?? null;
                if ($answer === null) {
                    return null;
                }
                $row = $answer->question->forLearner() + [
                    'your_response' => $answer->response['value'] ?? null,
                    'correct' => $answer->is_correct,
                    'marks_awarded' => $answer->marks_awarded,
                    'topic' => $answer->topic,
                ];
                if ($reveal) {
                    $row['answer_key'] = $answer->question->answer;
                    $row['explanation'] = $answer->question->explanation;
                }

                return $row;
            })->filter()->values()->all(),
        ];
    }
}
