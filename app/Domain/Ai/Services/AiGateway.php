<?php

namespace App\Domain\Ai\Services;

use App\Domain\Ai\Contracts\AiTutor;
use App\Domain\Ai\Guardrails\Eligibility;
use App\Domain\Ai\Guardrails\LearnerContext;
use App\Domain\Ai\Guardrails\Refusal;
use App\Domain\Ai\Guardrails\SafeguardingSignals;
use App\Domain\Ai\Guardrails\UsageLimits;
use App\Domain\Ai\Models\AiInteraction;
use App\Domain\Ai\Support\AiOutcome;
use App\Domain\Ai\Support\AiPrompt;
use App\Domain\Safety\Services\ContactRedactor;
use App\Models\User;
use Closure;

/**
 * The guarded path every assistant request takes (brief section 14, D-903):
 * configured? -> eligible (suspension, guardian consent, band) -> within limits -> task-specific refusals
 * (integrity, access) -> minimised prompt -> model -> output post-processing -> safeguarding log.
 * Nothing reaches the model unless every earlier step passed. Every request, answered or refused, is logged,
 * except when AI is not configured (then nothing happened and nothing is stored).
 */
class AiGateway
{
    public function __construct(
        private readonly AiTutor $ai,
        private readonly Eligibility $eligibility,
        private readonly UsageLimits $limits,
        private readonly ContactRedactor $redactor,
    ) {}

    public function configured(): bool
    {
        return $this->ai->configured();
    }

    public function modelFor(string $tier): ?string
    {
        return $this->ai->modelFor($tier);
    }

    /**
     * Personal-data minimisation for anything a person typed: contact details (email, phone, handles, chat links) are
     * replaced before the text is sent or stored. Names cannot be removed reliably; the prompt tells the model not to
     * repeat them, and the screens ask people not to type them (documented in R47_AI.md).
     */
    public function minimise(string $text): string
    {
        return $this->redactor->redact(trim($text))['text'];
    }

    /**
     * @param  Closure(): (AiPrompt|Refusal)  $build  builds the prompt only after the cheap checks pass
     * @param  array<string, mixed>  $context  metadata only (ids, codes), never text
     * @param  (Closure(AiOutcome): ?Refusal)|null  $validate  rejects an answer after the call (e.g. notation lost)
     */
    public function ask(
        User $user,
        LearnerContext $learner,
        string $role,
        string $task,
        string $requestText,
        Closure $build,
        array $context = [],
        ?string $mode = null,
        ?Refusal $preflight = null,
        ?Closure $validate = null,
    ): AiInteraction {
        $interaction = new AiInteraction([
            'user_id' => $user->id,
            'actor_role' => $role,
            'task' => $task,
            'age_band' => $learner->band,
            'age_known' => $learner->ageKnown,
            'mode' => $mode,
            'language' => $learner->language,
            'prompt_version' => (string) config('ai.prompt_version'),
            'request_text' => $requestText,
            'context' => $context + ['learner' => $learner->summary()],
        ]);

        if (! $this->ai->configured()) {
            return $interaction->fill(['status' => 'refused', 'refusal_code' => 'not_configured']);
        }

        $signal = SafeguardingSignals::detect($requestText);
        if ($signal !== null) {
            $interaction->fill(['flagged' => true, 'flag_reason' => 'request:'.$signal]);
        }

        $refusal = $this->eligibility->check($user, $learner, $role)
            ?? $this->limits->check($user, $role, $learner->band)
            ?? $preflight;
        $prompt = $refusal === null ? $build() : null;
        if ($prompt instanceof Refusal) {
            $refusal = $prompt;
        }
        if ($refusal !== null) {
            return $this->store($interaction->fill(['status' => 'refused', 'refusal_code' => $refusal->code]));
        }

        /** @var AiPrompt $prompt */
        $outcome = $this->ai->generate(new AiPrompt(
            system: $prompt->system,
            blocks: $prompt->blocks,
            tier: $prompt->tier,
            schema: $prompt->schema,
            pseudonymousUser: substr(hash_hmac('sha256', 'ai-user:'.$user->id, (string) config('app.key')), 0, 32),
        ));

        $interaction->fill([
            'model' => $outcome->model ?? $this->ai->modelFor($prompt->tier),
            'prompt_text' => $prompt->loggableText(),
            'input_tokens' => $outcome->inputTokens,
            'output_tokens' => $outcome->outputTokens,
            'cost_micro_usd' => $outcome->reachedModel() ? UsageLimits::costMicroUsd($outcome->model, $outcome->inputTokens, $outcome->outputTokens) : 0,
            'reached_model' => $outcome->reachedModel(),
        ]);

        if (! in_array($outcome->status, ['ok', 'incomplete'], true)) {
            return $this->store($interaction->fill([
                'status' => $outcome->status === 'refused_by_model' ? 'refused_by_model' : 'failed',
                'refusal_code' => $outcome->status === 'refused_by_model' ? ($outcome->refusalCategory ? 'model:'.$outcome->refusalCategory : 'model') : $outcome->errorCode,
            ]));
        }

        $rejected = $validate !== null && $outcome->ok() ? $validate($outcome) : null;
        if ($rejected !== null) {
            return $this->store($interaction->fill([
                'status' => 'refused', 'refusal_code' => $rejected->code,
                'output_text' => $outcome->text,
                'context' => $interaction->context + ['rejected_detail' => $rejected->detail],
            ]));
        }

        $text = $outcome->text;
        $data = $outcome->data;
        if ($learner->isMinorBehaviour()) {
            // Contact details never reach a minor from the assistant either.
            $text = $this->redactor->redact($text)['text'];
        }
        if ($signal === null && ($outputSignal = SafeguardingSignals::detect($text)) !== null && $learner->isMinorBehaviour()) {
            $interaction->fill(['flagged' => true, 'flag_reason' => 'output:'.$outputSignal]);
        }

        $extra = [];
        if ($signal !== null) {
            $extra['safety_note'] = SafeguardingSignals::helpText($learner->band);
        }
        if ($learner->language !== 'en') {
            $extra['language_note'] = 'Answered in '.$learner->languageName.'. AI answers in this language have not been checked by a native-speaking educator and may contain mistakes; English terms are kept in brackets where needed.';
        }

        return $this->store($interaction->fill([
            'status' => $outcome->status,
            'output_text' => $text,
            'output_data' => $data,
            'context' => $interaction->context + $extra,
        ]));
    }

    private function store(AiInteraction $interaction): AiInteraction
    {
        $days = $interaction->flagged ? (int) config('ai.evidence_retention_days', 730) : (int) config('ai.retention_days', 180);
        $interaction->purge_after = now()->addDays($days);
        $interaction->save();

        return $interaction;
    }
}
