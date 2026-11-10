<?php

namespace App\Domain\Ai\Http\Controllers;

use App\Domain\Ai\Guardrails\LearnerContextResolver;
use App\Domain\Ai\Guardrails\UsageLimits;
use App\Domain\Ai\Models\AiInteraction;
use App\Domain\Ai\Models\AiQuestionDraft;
use App\Domain\Ai\Services\AiGateway;
use App\Domain\Ai\Services\TutorAssistant;
use App\Domain\Learning\Enums\QuestionType;
use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\QuestionBank;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Tutor tools: outlines, quiz drafts (review before use), exercises, descriptions, struggles, library suggestions. */
class TutorAssistController extends AiController
{
    public function index(Request $request, AiGateway $gateway, TutorAssistant $assistant, LearnerContextResolver $contexts, UsageLimits $limits): JsonResponse|View
    {
        $user = $request->user();
        [$band] = $contexts->band($user);
        $payload = [
            'configured' => $gateway->configured(),
            'model' => $gateway->modelFor('standard'),
            'is_tutor' => $user->tutorProfile()->exists(),
            'tasks' => TutorAssistant::TASKS,
            'usage' => $limits->summary($user, 'tutor', $band),
            'struggles' => $assistant->aggregateMastery($user),
            'languages' => LearnerContextResolver::answerLanguages(),
        ];
        $drafts = AiQuestionDraft::query()->with('bank:id,title')->where('owner_id', $user->id)->where('status', 'draft')->latest('id')->limit(50)->get();
        if ($this->wantsJson($request)) {
            return response()->json(['data' => $payload + ['drafts' => $drafts->map->present()->values()]]);
        }

        return view('ai::tutor', $payload + [
            'drafts' => $drafts,
            'banks' => QuestionBank::query()->where('owner_id', $user->id)->orderBy('title')->get(['id', 'title']),
            'courses' => Course::query()->where('owner_id', $user->id)->orderBy('title')->get(['id', 'title']),
            'recent' => AiInteraction::query()->where('user_id', $user->id)->where('actor_role', 'tutor')->latest('id')->limit(10)->get(),
        ]);
    }

    public function ask(Request $request, TutorAssistant $assistant): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'task' => ['required', Rule::in(TutorAssistant::TASKS)],
            'text' => [Rule::requiredIf(fn () => in_array($request->input('task'), ['lesson_outline', 'revision_exercises', 'resource_suggestions'], true)), 'nullable', 'string', 'max:'.(int) config('ai.max_input_chars', 4000)],
            'language' => ['nullable', 'string', 'max:20'],
            'subject_id' => ['nullable', 'integer'],
            'level_id' => ['nullable', 'integer'],
            'count' => ['nullable', 'integer', 'min:1', 'max:10'],
            'duration_minutes' => ['nullable', 'integer', 'min:15', 'max:240'],
            'bank_id' => ['required_if:task,quiz_drafts', 'nullable', 'integer'],
            'types' => ['nullable', 'array'],
            'types.*' => [Rule::in(QuestionType::values())],
            'difficulty' => ['nullable', Rule::in(['introductory', 'standard', 'advanced'])],
            'course_id' => ['required_if:task,course_description', 'nullable', 'integer'],
        ]);

        $interaction = $assistant->handle($request->user(), $data['task'], $data);
        $extra = $data['task'] === 'quiz_drafts' && $interaction->exists
            ? ['drafts' => $interaction->drafts()->get()->map->present()->values()->all()]
            : [];

        return $this->wantsJson($request) ? $this->interactionResponse($interaction, $extra) : $this->backWith($interaction, 'ai.interactions.show');
    }

    public function accept(Request $request, AiQuestionDraft $draft, TutorAssistant $assistant): JsonResponse|RedirectResponse
    {
        $edits = $request->validate([
            'prompt' => ['sometimes', 'string', 'max:5000'],
            'options' => ['sometimes', 'nullable', 'array', 'max:10'],
            'answer' => ['sometimes', 'array'],
            'explanation' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'marks' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $draft = $assistant->acceptDraft($request->user(), $draft, $edits);

        return $this->wantsJson($request)
            ? response()->json(['data' => $draft->present()])
            : back()->with('success', 'Question added to your bank. It is yours now: learners see it only when you put it in an assessment.');
    }

    public function discard(Request $request, AiQuestionDraft $draft, TutorAssistant $assistant): JsonResponse|RedirectResponse
    {
        $draft = $assistant->discardDraft($request->user(), $draft);

        return $this->wantsJson($request) ? response()->json(['data' => $draft->present()]) : back()->with('success', 'Draft discarded.');
    }
}
