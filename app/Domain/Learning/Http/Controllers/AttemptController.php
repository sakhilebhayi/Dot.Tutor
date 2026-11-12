<?php

namespace App\Domain\Learning\Http\Controllers;

use App\Domain\Learning\Models\Assessment;
use App\Domain\Learning\Models\Attempt;
use App\Domain\Learning\Services\AttemptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Taking an assessment: start (or resume), submit for server-side marking, read the result. */
class AttemptController extends LearningController
{
    public function __construct(private AttemptService $attempts) {}

    public function start(Request $request, Assessment $assessment): JsonResponse|RedirectResponse
    {
        if (! $this->attempts->canAttempt($request->user(), $assessment)) {
            return $this->wantsJson($request) ? $this->error('forbidden', 'This assessment is not open to you.', 403) : abort(403);
        }
        $attempt = $this->attempts->start($request->user(), $assessment);

        return $this->wantsJson($request)
            ? response()->json(['data' => $this->attemptPayload($attempt)], 201)
            : redirect()->route('learning.assessments.take', $assessment);
    }

    /** The quiz page: shows the open attempt, or a start button (starting is a POST, never a side effect of GET). */
    public function take(Request $request, Assessment $assessment): View
    {
        abort_unless($this->attempts->canAttempt($request->user(), $assessment), 403);
        $attempt = Attempt::query()->where('assessment_id', $assessment->id)->where('user_id', $request->user()->id)
            ->where('status', 'in_progress')->first();
        $previous = Attempt::query()->where('assessment_id', $assessment->id)->where('user_id', $request->user()->id)
            ->where('status', 'submitted')->orderByDesc('submitted_at')->get(['id', 'score', 'max_score', 'percent', 'submitted_at']);

        return view('learning.assessments.take', [
            'assessment' => $assessment,
            'attempt' => $attempt,
            'data' => $attempt ? $this->attemptPayload($attempt) : null,
            'previous' => $previous,
        ]);
    }

    public function submit(Request $request, Attempt $attempt): JsonResponse|RedirectResponse
    {
        abort_unless($attempt->user_id === $request->user()->id, 404);
        $answers = $request->validate(['answers' => ['nullable', 'array', 'max:500']])['answers'] ?? [];
        $attempt = $this->attempts->submit($attempt, $answers);

        return $this->wantsJson($request)
            ? response()->json(['data' => $this->attempts->result($attempt)])
            : redirect()->route('learning.attempts.show', $attempt);
    }

    public function show(Request $request, Attempt $attempt): JsonResponse|View
    {
        $user = $request->user();
        abort_unless($attempt->user_id === $user->id || $attempt->assessment->isOwnedBy($user), 404);
        if ($attempt->status !== 'submitted') {
            return $this->wantsJson($request) ? response()->json(['data' => $this->attemptPayload($attempt)]) : redirect()->route('learning.assessments.take', $attempt->assessment);
        }
        $result = $this->attempts->result($attempt);

        return $this->wantsJson($request) ? response()->json(['data' => $result]) : view('learning.assessments.result', ['attempt' => $attempt, 'result' => $result]);
    }

    /** @return array<string, mixed> */
    private function attemptPayload(Attempt $attempt): array
    {
        $assessment = $attempt->assessment;

        return [
            'attempt_id' => $attempt->id,
            'assessment' => ['id' => $assessment->id, 'title' => $assessment->title, 'kind' => $assessment->kind->value, 'instructions' => $assessment->instructions, 'pass_percent' => $assessment->pass_percent],
            'status' => $attempt->status,
            'started_at' => $attempt->started_at->toIso8601String(),
            'deadline_at' => $attempt->deadline_at?->toIso8601String(),
            'questions' => $this->attempts->questionsFor($attempt),
        ];
    }
}
