<?php

namespace App\Domain\Learning\Http\Controllers;

use App\Domain\Community\Support\Names;
use App\Domain\Learning\Contracts\Seams\AcademicScopeLookup;
use App\Domain\Learning\Contracts\Seams\SafeguardingCheck;
use App\Domain\Learning\Contracts\Seams\TutorStanding;
use App\Domain\Learning\Enums\AssessmentKind;
use App\Domain\Learning\Enums\QuestionType;
use App\Domain\Learning\Models\Assessment;
use App\Domain\Learning\Models\Assignment;
use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\Enrolment;
use App\Domain\Learning\Models\Question;
use App\Domain\Learning\Models\QuestionBank;
use App\Domain\Learning\Services\Marker;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Authoring: question banks per topic, questions with answer keys, assessments, and assigning them to learners. */
class AssessmentController extends LearningController
{
    public function banks(Request $request): JsonResponse
    {
        return response()->json(['data' => QuestionBank::query()->where('owner_id', $request->user()->id)->withCount('questions')->orderBy('id')->get()]);
    }

    public function storeBank(Request $request, AcademicScopeLookup $academic): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'subject_id' => ['nullable', 'integer'],
            'topic_id' => ['nullable', 'integer'],
            'strand' => ['nullable', 'string', 'max:120'],
            'topic' => ['required', 'string', 'max:120'],
            'language' => ['nullable', 'string', 'max:20'],
        ]);
        if ($errors = $academic->validate(null, null, $data['subject_id'] ?? null, $data['topic_id'] ?? null)) {
            throw ValidationException::withMessages($errors);
        }
        $bank = QuestionBank::create($data + ['owner_id' => $request->user()->id, 'language' => $data['language'] ?? 'en']);

        return response()->json(['data' => $bank], 201);
    }

    /** The owner sees the answer keys; nobody else can open a bank. */
    public function showBank(Request $request, QuestionBank $bank): JsonResponse
    {
        abort_unless($bank->owner_id === $request->user()->id, 404);

        return response()->json(['data' => $bank->toArray() + [
            'questions' => $bank->questions()->orderBy('id')->get()->map(fn (Question $q) => $q->makeVisible(['answer', 'explanation'])->toArray()),
        ]]);
    }

    public function storeQuestion(Request $request, QuestionBank $bank, Marker $marker): JsonResponse
    {
        abort_unless($bank->owner_id === $request->user()->id, 404);
        $data = $request->validate([
            'type' => ['required', Rule::in(QuestionType::values())],
            'prompt' => ['required', 'string', 'max:5000'],
            'options' => ['nullable', 'array', 'max:10'],
            'answer' => ['required', 'array'],
            'explanation' => ['nullable', 'string', 'max:5000'],
            'topic' => ['nullable', 'string', 'max:120'],
            'marks' => ['nullable', 'integer', 'min:1', 'max:100'],
            'difficulty' => ['nullable', Rule::in(['introductory', 'standard', 'advanced'])],
        ]);
        $key = $marker->normaliseKey(QuestionType::from($data['type']), $data['options'] ?? null, $data['answer']);
        $question = $bank->questions()->create([
            'type' => $data['type'],
            'prompt' => $data['prompt'],
            'options' => $key['options'],
            'answer' => $key['answer'],
            'explanation' => $data['explanation'] ?? null,
            'topic' => $data['topic'] ?? null,
            'marks' => $data['marks'] ?? 1,
            'difficulty' => $data['difficulty'] ?? null,
        ]);

        return response()->json(['data' => $question->makeVisible(['answer', 'explanation'])], 201);
    }

    /** Retire rather than edit: past attempts keep pointing at the question they were marked against. */
    public function retireQuestion(Request $request, Question $question): JsonResponse
    {
        abort_unless($question->bank->owner_id === $request->user()->id, 404);
        $question->forceFill(['retired_at' => now()])->save();

        return response()->json(['data' => ['id' => $question->id, 'retired' => true]]);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => [
            'authored' => Assessment::query()->where('owner_id', $user->id)->withCount('questions')->orderBy('id')->get(),
            'assigned_to_me' => Assignment::query()->with('assessment:id,title,kind,time_limit_minutes,max_attempts')->where('learner_id', $user->id)->orderBy('due_at')->get()
                ->map(fn ($a) => ['assessment' => $a->assessment, 'due_at' => $a->due_at?->toIso8601String()]),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'instructions' => ['nullable', 'string', 'max:5000'],
            'kind' => ['required', Rule::in(AssessmentKind::values())],
            'course_id' => ['nullable', 'integer'],
            'pass_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'time_limit_minutes' => ['nullable', 'integer', 'min:1', 'max:600'],
            'max_attempts' => ['nullable', 'integer', 'min:1', 'max:100'],
            'shuffle_questions' => ['nullable', 'boolean'],
            'reveal_answers' => ['nullable', Rule::in(['after_submit', 'never'])],
        ]);
        $kind = AssessmentKind::from($data['kind']);
        if ($kind === AssessmentKind::CourseQuiz) {
            $owns = isset($data['course_id']) && Course::query()->whereKey($data['course_id'])->where('owner_id', $request->user()->id)->exists();
            if (! $owns) {
                throw ValidationException::withMessages(['course_id' => 'A course quiz belongs to one of your courses.']);
            }
        } elseif (isset($data['course_id'])) {
            $owns = Course::query()->whereKey($data['course_id'])->where('owner_id', $request->user()->id)->exists();
            $owns || throw ValidationException::withMessages(['course_id' => 'That is not one of your courses.']);
        }
        $assessment = Assessment::create([
            'owner_id' => $request->user()->id,
            'course_id' => $data['course_id'] ?? null,
            'title' => $data['title'],
            'instructions' => $data['instructions'] ?? null,
            'kind' => $kind,
            'pass_percent' => $data['pass_percent'] ?? 50,
            'time_limit_minutes' => $data['time_limit_minutes'] ?? null,
            'max_attempts' => $data['max_attempts'] ?? null,
            'shuffle_questions' => $data['shuffle_questions'] ?? true,
            'reveal_answers' => $data['reveal_answers'] ?? 'after_submit',
        ]);

        return response()->json(['data' => $assessment], 201);
    }

    public function show(Request $request, Assessment $assessment): JsonResponse
    {
        Gate::authorize('manage', $assessment);

        return response()->json(['data' => $assessment->toArray() + [
            'questions' => $assessment->questions()->get()->map(fn (Question $q) => $q->makeVisible(['answer', 'explanation'])->toArray()),
            'assignments' => $assessment->assignments()->with('assessment:id')->get(['id', 'learner_id', 'due_at']),
        ]]);
    }

    public function attachQuestions(Request $request, Assessment $assessment): JsonResponse
    {
        Gate::authorize('manage', $assessment);
        $ids = $request->validate(['question_ids' => ['required', 'array', 'min:1', 'max:200'], 'question_ids.*' => ['integer', 'distinct']])['question_ids'];
        $owned = Question::query()->whereIn('id', $ids)->whereNull('retired_at')
            ->whereHas('bank', fn ($q) => $q->where('owner_id', $request->user()->id))->pluck('id')->all();
        if (count($owned) !== count($ids)) {
            throw ValidationException::withMessages(['question_ids' => 'Questions must come from your own question banks and not be retired.']);
        }
        DB::transaction(function () use ($assessment, $ids) {
            $position = (int) DB::table('learning_assessment_questions')->where('assessment_id', $assessment->id)->max('position');
            foreach ($ids as $id) {
                $assessment->questions()->syncWithoutDetaching([$id => ['position' => ++$position]]);
            }
        });

        return response()->json(['data' => ['questions' => $assessment->questions()->count()]]);
    }

    /**
     * Assign a tutor assessment to a learner the tutor actually teaches: a confirmed or completed session with them,
     * or an active enrolment in one of the tutor's courses. The safeguarding gate is asked as well.
     */
    public function assign(Request $request, Assessment $assessment, TutorStanding $tutors, SafeguardingCheck $safeguarding): JsonResponse
    {
        Gate::authorize('manage', $assessment);
        $data = $request->validate([
            'learner_id' => ['required', 'integer', 'exists:users,id'],
            'due_at' => ['nullable', 'date', 'after:now'],
        ]);
        $tutor = $request->user();
        $learner = User::query()->findOrFail($data['learner_id']);
        $teaches = $tutors->hasTaught($tutor, $learner) || Enrolment::query()->where('user_id', $learner->id)->where('status', 'active')
            ->whereIn('course_id', Course::query()->where('owner_id', $tutor->id)->select('id'))->exists();
        if (! $teaches || $learner->id === $tutor->id) {
            return $this->error('no_relationship', 'You can only assign work to learners you teach.', 403);
        }
        $decision = $safeguarding->allows($tutor, 'assign_work', $learner);
        if (! $decision->allowed) {
            return $this->error('not_allowed', $decision->reason ?? 'Assigning work to this learner is not allowed.', 403);
        }
        $assignment = Assignment::query()->updateOrCreate(
            ['assessment_id' => $assessment->id, 'learner_id' => $learner->id],
            ['assigned_by' => $tutor->id, 'due_at' => $data['due_at'] ?? null],
        );

        return response()->json(['data' => ['id' => $assignment->id, 'learner_id' => $learner->id, 'due_at' => $assignment->due_at?->toIso8601String()]], 201);
    }

    /** The author sees every submitted attempt on their assessment, with scores; nothing else about the learners. */
    public function attempts(Request $request, Assessment $assessment): JsonResponse
    {
        Gate::authorize('manage', $assessment);

        return response()->json(['data' => $assessment->attempts()->with('user:id,name')->where('status', 'submitted')->orderByDesc('submitted_at')->get()
            ->map(fn ($a) => ['id' => $a->id, 'learner' => $a->user ? Names::short($a->user) : null, 'score' => $a->score, 'max_score' => $a->max_score, 'percent' => $a->percent, 'passed' => $a->passed, 'late' => $a->late, 'submitted_at' => $a->submitted_at->toIso8601String()])]);
    }
}
