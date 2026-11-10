<?php

namespace App\Domain\Ai\Services;

use App\Domain\Academic\Models\LearnerProfile;
use App\Domain\Academic\Services\NeedParser;
use App\Domain\Ai\Guardrails\LearnerContextResolver;
use App\Domain\Ai\Guardrails\PromptBuilder;
use App\Domain\Ai\Models\AiInteraction;
use App\Domain\Ai\Support\AiPrompt;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * "Describe what you need" with an optional AI fallback (D-913). The academic NeedParser (deterministic) runs first.
 * Only when it is unsure (no level or no subject found) and the person asks for AI help does the model rewrite the
 * free text into plain fields; the SAME deterministic parser then matches those fields against the seeded catalogue,
 * so the model never invents a level, subject or language id. The result waits for the person to confirm it; only a
 * confirmed result produces a link into tutor matching.
 */
class NeedAssistant
{
    public function __construct(
        private readonly NeedParser $parser,
        private readonly AiGateway $gateway,
        private readonly LearnerContextResolver $contexts,
    ) {}

    /** @return array<string, mixed> */
    public function describe(User $user, string $text, bool $useAi): array
    {
        $curriculumId = LearnerProfile::query()->where('user_id', $user->id)->value('curriculum_id');
        $parsed = $this->parser->parse($text, $curriculumId);
        $unsure = self::isUnsure($parsed);

        $base = ['source' => 'parser', 'unsure' => $unsure, 'ai_available' => $this->gateway->configured(), 'result' => $parsed, 'needs_confirmation' => true];
        if (! $unsure || ! $useAi) {
            return $base;
        }

        $clean = $this->gateway->minimise($text);
        $interaction = $this->gateway->ask(
            $user, $this->contexts->for($user), 'learner', 'describe_need', $clean,
            fn () => new AiPrompt(
                system: PromptBuilder::needNormaliser(),
                blocks: [['type' => 'text', 'text' => "<learner_request>\n{$clean}\n</learner_request>"]],
                tier: 'light',
                schema: [
                    'type' => 'object',
                    'properties' => [
                        'grade_or_level' => ['type' => 'string'],
                        'subjects' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'teaching_languages' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                    'required' => ['grade_or_level', 'subjects', 'teaching_languages'],
                    'additionalProperties' => false,
                ],
            ),
        );

        if ($interaction->status !== 'ok') {
            return $base + ['interaction' => $interaction->exists ? $interaction->present() : null, 'ai_message' => $interaction->statusMessage()];
        }

        $fields = $interaction->output_data;
        $normalised = trim(implode(' ', array_filter([
            (string) ($fields['grade_or_level'] ?? ''),
            implode(' and ', array_map('strval', array_slice((array) ($fields['subjects'] ?? []), 0, 4))),
            ($langs = array_slice((array) ($fields['teaching_languages'] ?? []), 0, 3)) ? 'in '.implode(' and in ', array_map('strval', $langs)) : '',
        ])));
        $aiParsed = $this->parser->parse($normalised !== '' ? $normalised : $text, $curriculumId);

        $interaction->forceFill([
            'status' => 'awaiting_confirmation',
            'output_data' => ['fields' => $fields, 'normalised' => $normalised, 'result' => $aiParsed],
        ])->save();

        return [
            'source' => 'ai_assisted',
            'unsure' => self::isUnsure($aiParsed),
            'ai_available' => true,
            'result' => $aiParsed,
            'normalised' => $normalised,
            'needs_confirmation' => true,
            'interaction' => $interaction->present(),
            'confirm_url' => route('ai.need.confirm', $interaction),
        ];
    }

    /**
     * The person confirms (and may narrow) the AI-assisted reading; only candidates the parser produced are accepted.
     *
     * @param  array{subject_id: int, level_id?: ?int, languages?: list<string>}  $choice
     */
    public function confirm(User $user, AiInteraction $interaction, array $choice): string
    {
        abort_unless($interaction->user_id === $user->id && $interaction->task === 'describe_need', 404);
        if ($interaction->status !== 'awaiting_confirmation') {
            throw ValidationException::withMessages(['interaction' => 'This suggestion was already confirmed or cannot be confirmed.']);
        }
        $result = $interaction->output_data['result'] ?? [];
        $subjects = array_column($result['subjects'] ?? [], 'id');
        $levels = array_column($result['levels'] ?? [], 'id');
        $languages = array_column($result['teaching_languages'] ?? [], 'code');

        if (! in_array((int) $choice['subject_id'], $subjects, true)) {
            throw ValidationException::withMessages(['subject_id' => 'Choose one of the subjects found in your description.']);
        }
        if (! empty($choice['level_id']) && ! in_array((int) $choice['level_id'], $levels, true)) {
            throw ValidationException::withMessages(['level_id' => 'Choose one of the levels found in your description.']);
        }
        $chosenLanguages = array_values(array_intersect((array) ($choice['languages'] ?? []), $languages));

        $interaction->forceFill(['status' => 'confirmed', 'confirmed_at' => now()])->save();

        return route('marketplace.find', array_filter([
            'subject_id' => (int) $choice['subject_id'],
            'level_id' => $choice['level_id'] ?? null,
            'languages' => $chosenLanguages !== [] ? implode(',', $chosenLanguages) : null,
        ]));
    }

    /** @param  array<string, mixed>  $parsed */
    public static function isUnsure(array $parsed): bool
    {
        return ($parsed['levels'] ?? []) === [] || ($parsed['subjects'] ?? []) === [];
    }
}
