<?php

namespace App\Domain\Ai\Http\Controllers;

use App\Domain\Ai\Guardrails\LearnerContextResolver;
use App\Domain\Ai\Guardrails\PromptBuilder;
use App\Domain\Ai\Guardrails\UsageLimits;
use App\Domain\Ai\Models\AiInteraction;
use App\Domain\Ai\Services\AiGateway;
use App\Domain\Ai\Services\StudentAssistant;
use App\Domain\Safety\Audit;
use App\Domain\Safety\Contracts\ReportSink;
use App\Domain\Safety\Enums\ReportCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** The learner assistant: ask, read an answer, report it. */
class AssistantController extends AiController
{
    public const REPORT_CATEGORIES = ['misinformation', 'harassment', 'cheating_request', 'other'];

    public function index(Request $request, AiGateway $gateway, LearnerContextResolver $contexts, UsageLimits $limits): JsonResponse|View
    {
        $user = $request->user();
        $context = $contexts->for($user);
        $recent = AiInteraction::query()->where('user_id', $user->id)->where('actor_role', 'learner')
            ->latest('id')->limit(10)->get();
        $payload = [
            'configured' => $gateway->configured(),
            'model' => $gateway->modelFor('standard'),
            'tasks' => StudentAssistant::TASKS,
            'band' => $context->band,
            'age_known' => $context->ageKnown,
            'language' => $context->language,
            'languages' => LearnerContextResolver::answerLanguages(),
            'modes' => $context->band === 'adult' ? PromptBuilder::MODES : ['understand'],
            'usage' => $limits->summary($user, 'learner', $context->band),
            'label' => AiInteraction::DISCLAIMER,
        ];
        if ($this->wantsJson($request)) {
            return response()->json(['data' => $payload + ['recent' => $recent->map->present()->values()]]);
        }

        return view('ai::assistant', $payload + ['recent' => $recent, 'context' => $context]);
    }

    public function ask(Request $request, StudentAssistant $assistant): JsonResponse|RedirectResponse
    {
        $max = (int) config('ai.max_input_chars', 4000);
        $data = $request->validate([
            'task' => ['required', Rule::in(StudentAssistant::TASKS)],
            'text' => [Rule::requiredIf(fn () => ! in_array($request->input('task'), ['explain_mistake', 'study_plan'], true)), 'nullable', 'string', 'max:'.$max],
            'language' => ['nullable', 'string', 'max:20'],
            'mode' => ['nullable', Rule::in(PromptBuilder::MODES)],
            'subject_id' => ['nullable', 'integer'],
            'count' => ['nullable', 'integer', 'min:1', 'max:10'],
            'weeks' => ['nullable', 'integer', 'min:1', 'max:12'],
            'hours_per_week' => ['nullable', 'integer', 'min:1', 'max:40'],
            'attempt_id' => ['required_if:task,explain_mistake', 'nullable', 'integer'],
            'question_id' => ['required_if:task,explain_mistake', 'nullable', 'integer'],
            'resource_id' => ['required_if:task,resource_help', 'nullable', 'integer'],
        ]);

        $interaction = $assistant->handle($request->user(), $data['task'], $data);

        return $this->wantsJson($request) ? $this->interactionResponse($interaction) : $this->backWith($interaction, 'ai.interactions.show');
    }

    public function show(Request $request, AiInteraction $interaction): JsonResponse|View
    {
        $user = $request->user();
        $own = $interaction->user_id === $user->id;
        abort_unless($own || Gate::allows('ai.review-log'), 404);
        if (! $own) {
            Audit::record($user, 'ai.interaction.read', $interaction, ['task' => $interaction->task]);
        }

        if ($this->wantsJson($request)) {
            return response()->json(['data' => $interaction->present()]);
        }

        return view('ai::interaction', ['interaction' => $interaction, 'data' => $interaction->present(), 'own' => $own,
            'categories' => collect(self::REPORT_CATEGORIES)->mapWithKeys(fn ($c) => [$c => ReportCategory::from($c)->label()])->all()]);
    }

    /** The report button: the interaction goes to safety's moderation queue (ReportSink) and is kept as evidence. */
    public function report(Request $request, AiInteraction $interaction, ReportSink $reports): JsonResponse|RedirectResponse
    {
        abort_unless($interaction->user_id === $request->user()->id, 404);
        $data = $request->validate([
            'category' => ['required', Rule::in(self::REPORT_CATEGORIES)],
            'details' => ['nullable', 'string', 'max:2000'],
        ]);

        $report = $reports->report($request->user(), $interaction, ReportCategory::from($data['category']), $data['details'] ?? null);
        $interaction->forceFill(['reported_at' => now()])->save();
        $interaction->keepAsEvidence();

        return $this->wantsJson($request)
            ? response()->json(['data' => ['report_id' => $report->id, 'status' => $report->status]], 201)
            : back()->with('success', 'Thank you. The safety team will look at this answer.');
    }
}
