<?php

namespace App\Domain\Ai\Services;

use App\Domain\Ai\Guardrails\IntegrityGuard;
use App\Domain\Ai\Guardrails\LearnerContext;
use App\Domain\Ai\Guardrails\LearnerContextResolver;
use App\Domain\Ai\Guardrails\PromptBuilder;
use App\Domain\Ai\Guardrails\Refusal;
use App\Domain\Ai\Models\AiInteraction;
use App\Domain\Ai\Seams\LearningRecords;
use App\Domain\Ai\Support\AiPrompt;
use App\Domain\Learning\Enums\QuestionType;
use App\Models\User;

/**
 * Learner tasks (brief section 14, student assistance): explain, simplify, practice questions, revision summary,
 * explain a mistake on a real submitted attempt, study plan from real mastery, help with a library resource the
 * learner may open. Every task goes through AiGateway; free text goes through the integrity guard first.
 */
class StudentAssistant
{
    public const TASKS = ['explain', 'simplify', 'practice', 'revision_summary', 'explain_mistake', 'study_plan', 'resource_help'];

    private const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function __construct(
        private readonly AiGateway $gateway,
        private readonly LearnerContextResolver $contexts,
        private readonly IntegrityGuard $integrity,
        private readonly LearningRecords $learning,
    ) {}

    /** @param  array<string, mixed>  $input  validated by the controller */
    public function handle(User $user, string $task, array $input): AiInteraction
    {
        $learner = $this->contexts->for($user, $input['language'] ?? null);
        $mode = in_array($input['mode'] ?? null, PromptBuilder::MODES, true) ? $input['mode'] : 'understand';
        if ($learner->band !== 'adult') {
            $mode = 'understand';
        }

        return match ($task) {
            'explain_mistake' => $this->explainMistake($user, $learner, (int) $input['attempt_id'], (int) $input['question_id']),
            'study_plan' => $this->studyPlan($user, $learner, $input),
            'resource_help' => $this->resourceHelp($user, $learner, (int) $input['resource_id'], (string) $input['text'], $mode),
            default => $this->freeText($user, $learner, $task, $input, $mode),
        };
    }

    /** @param  array<string, mixed>  $input */
    private function freeText(User $user, LearnerContext $learner, string $task, array $input, string $mode): AiInteraction
    {
        $text = $this->gateway->minimise((string) $input['text']);
        $count = max(1, min(10, (int) ($input['count'] ?? 5)));

        [$instruction, $schema] = match ($task) {
            'explain' => ['Explain the concept or question the learner asks about, at their level, with one short example. End with one question that checks they understood.', null],
            'simplify' => ['Rewrite the text the learner gives you in simpler words at their level. Keep every fact, formula and number exactly. Then list up to five key words with a one-line meaning each.', null],
            'revision_summary' => ['Write a revision summary of the topic: the key ideas, formulas or definitions, and the common mistakes to avoid, at their level. Keep it to one page.', null],
            'practice' => ["Write {$count} practice questions on the topic, at their level, from easier to harder. For each: the question, a hint that does not give the answer away, the worked answer, and a short explanation. The learner sees the hint first and opens the answer only when they choose.", self::practiceSchema()],
        };

        return $this->gateway->ask(
            $user, $learner, 'learner', $task, $text,
            fn () => new AiPrompt(
                system: PromptBuilder::learner($learner, $instruction, $mode),
                blocks: [['type' => 'text', 'text' => "<learner_request>\n{$text}\n</learner_request>"]],
                schema: $schema,
            ),
            context: array_filter(['subject_id' => $input['subject_id'] ?? null, 'count' => $task === 'practice' ? $count : null]),
            mode: $mode,
            preflight: $this->integrity->check($user, $text),
        );
    }

    private function explainMistake(User $user, LearnerContext $learner, int $attemptId, int $questionId): AiInteraction
    {
        $attempt = $this->learning->attempt($attemptId);
        $context = ['attempt_id' => $attemptId, 'question_id' => $questionId];

        $preflight = match (true) {
            $attempt === null => new Refusal('not_found'),
            $attempt->user_id !== $user->id => new Refusal('not_your_attempt'),
            $attempt->status !== 'submitted' => new Refusal('attempt_not_submitted'),
            $this->learning->timedAttemptsInProgress($user)->isNotEmpty() => new Refusal('assessment_in_progress'),
            default => null,
        };
        $answer = $preflight === null ? $this->learning->submittedAnswer($user, $attemptId, $questionId) : null;
        if ($preflight === null && $answer === null) {
            $preflight = new Refusal('not_found');
        }
        if ($preflight === null && $answer->is_correct) {
            $preflight = new Refusal('answer_was_correct');
        }

        $build = function () use ($attempt, $answer, $learner) {
            $question = $answer->question;
            $reveal = $attempt->assessment->reveal_answers === 'after_submit';
            $lines = [
                'Question ('.str_replace('_', ' ', $question->type->value).', '.$question->marks.' mark'.($question->marks === 1 ? '' : 's').'):',
                $question->prompt,
            ];
            foreach ($question->options ?? [] as $option) {
                $lines[] = $option['key'].') '.$option['text'];
            }
            $lines[] = '';
            $lines[] = 'The learner answered: '.$this->formatResponse($question->type, $answer->response['value'] ?? null, $question->options ?? []);
            $lines[] = 'Marked incorrect: '.$answer->marks_awarded.' of '.$answer->marks_possible.'.';
            if ($reveal) {
                $lines[] = 'Correct answer (from the answer key): '.$this->formatKey($question->type, $question->answer ?? [], $question->options ?? []);
                if ($question->explanation) {
                    $lines[] = 'The tutor\'s explanation: '.$question->explanation;
                }
                $instruction = 'Explain to the learner why their answer was marked wrong and what the correct reasoning is, using their actual answer above. Point out the likely misconception, then give one similar question for them to try (without its answer).';
            } else {
                $lines[] = 'The tutor has chosen not to show the correct answer for this assessment. Do not state, hint at or rule out the correct option or final value.';
                $instruction = 'Using the learner\'s actual answer above, explain which idea or step they should revisit and what kind of mistake it may show, without revealing the correct answer. Then give one similar question for them to practise (without its answer).';
            }

            return new AiPrompt(
                system: PromptBuilder::learner($learner, $instruction),
                blocks: [['type' => 'text', 'text' => implode("\n", $lines)]],
            );
        };

        return $this->gateway->ask(
            $user, $learner, 'learner', 'explain_mistake', 'Explain my mistake (attempt '.$attemptId.', question '.$questionId.')',
            $build, context: $context + ['subject_id' => $answer?->subject_id], mode: 'understand', preflight: $preflight,
        );
    }

    /** @param  array<string, mixed>  $input */
    private function studyPlan(User $user, LearnerContext $learner, array $input): AiInteraction
    {
        $goal = $this->gateway->minimise((string) ($input['text'] ?? ''));
        $weeks = max(1, min(12, (int) ($input['weeks'] ?? 4)));
        $hours = max(1, min(40, (int) ($input['hours_per_week'] ?? 3)));
        $mastery = $this->learning->mastery($user);

        $rows = array_map(fn (array $row) => '- '.$row['topic'].($row['strand'] ? ' ('.$row['strand'].')' : '').': '
            .str_replace('_', ' ', $row['level'])
            .($row['recent_accuracy_percent'] !== null ? ', recent accuracy '.$row['recent_accuracy_percent'].'%' : '')
            .', trend '.$row['trend'].', '.$row['answered'].' answers', array_slice($mastery, 0, 30));

        $data = $rows === []
            ? 'No marked answers yet, so there is no measured evidence of strengths and weaknesses. Say so, and build the plan from the goal alone.'
            : "Measured from the learner's marked answers on Dot.Tutor (not an official result):\n".implode("\n", $rows);

        return $this->gateway->ask(
            $user, $learner, 'learner', 'study_plan', $goal,
            fn () => new AiPrompt(
                system: PromptBuilder::learner($learner, "Write a study plan for {$weeks} week(s) at about {$hours} hour(s) a week. Base the priorities on the measured topic data given (topics that need attention first), and say which data each priority comes from. Do not claim progress or weaknesses the data does not show. Include short self-checks each week."),
                blocks: [['type' => 'text', 'text' => $data."\n\n<learner_request>\nMy goal: ".($goal !== '' ? $goal : 'not stated')."\n</learner_request>"]],
            ),
            context: ['weeks' => $weeks, 'hours_per_week' => $hours, 'mastery_topics' => count($rows)],
            mode: 'understand',
            preflight: $goal !== '' ? $this->integrity->check($user, $goal) : null,
        );
    }

    private function resourceHelp(User $user, LearnerContext $learner, int $resourceId, string $question, string $mode): AiInteraction
    {
        $question = $this->gateway->minimise($question);
        $resource = $this->learning->resourceIfReadable($user, $resourceId);
        $preflight = $resource === null ? new Refusal('not_found') : $this->integrity->check($user, $question);

        $build = function () use ($resource, $question, $learner, $mode) {
            $mime = (string) $resource->mime;
            $limitKb = $mime === 'application/pdf' ? (int) config('ai.resource.max_pdf_kb') : (int) config('ai.resource.max_image_kb');
            if (! ($mime === 'application/pdf' || in_array($mime, self::IMAGE_TYPES, true)) || (int) $resource->size > $limitKb * 1024) {
                return new Refusal('resource_not_readable');
            }
            $bytes = $this->learning->resourceBytes($resource);
            if ($bytes === null) {
                return new Refusal('not_found');
            }
            $file = $mime === 'application/pdf'
                ? ['type' => 'document', 'media_type' => $mime, 'data' => base64_encode($bytes), 'label' => 'library resource '.$resource->id]
                : ['type' => 'image', 'media_type' => $mime, 'data' => base64_encode($bytes), 'label' => 'library resource '.$resource->id];

            return new AiPrompt(
                system: PromptBuilder::learner($learner, 'The learner is studying the attached resource "'.$resource->title.'" from the Dot.Tutor library and has a question about it. Answer from the resource; say clearly when something is not in it. Quote at most one short line from it.', $mode),
                blocks: [$file, ['type' => 'text', 'text' => "<learner_request>\n{$question}\n</learner_request>"]],
            );
        };

        return $this->gateway->ask(
            $user, $learner, 'learner', 'resource_help', $question, $build,
            context: ['resource_id' => $resourceId, 'subject_id' => $resource?->subject_id],
            mode: $mode, preflight: $preflight,
        );
    }

    /** @param  list<array{key: string, text: string}>  $options */
    private function formatResponse(QuestionType $type, mixed $response, array $options): string
    {
        if ($response === null || $response === '' || $response === []) {
            return '(no answer)';
        }
        $text = fn (string $key) => $key.') '.(collect($options)->firstWhere('key', $key)['text'] ?? '');

        return match ($type) {
            QuestionType::SingleChoice => $text((string) $response),
            QuestionType::MultiSelect => implode('; ', array_map(fn ($k) => $text((string) $k), (array) $response)),
            default => is_scalar($response) ? (string) $response : json_encode($response),
        };
    }

    /**
     * @param  array<string, mixed>  $key
     * @param  list<array{key: string, text: string}>  $options
     */
    private function formatKey(QuestionType $type, array $key, array $options): string
    {
        $text = fn (string $k) => $k.') '.(collect($options)->firstWhere('key', $k)['text'] ?? '');

        return match ($type) {
            QuestionType::SingleChoice => $text((string) ($key['key'] ?? '')),
            QuestionType::MultiSelect => implode('; ', array_map(fn ($k) => $text((string) $k), $key['keys'] ?? [])),
            QuestionType::Numeric => $key['value'].(($key['tolerance'] ?? 0) > 0 ? ' (within '.$key['tolerance'].')' : ''),
            QuestionType::ShortText => implode(' or ', $key['accepted'] ?? []),
        };
    }

    /** @return array<string, mixed> */
    public static function practiceSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'questions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'question' => ['type' => 'string'],
                            'hint' => ['type' => 'string'],
                            'answer' => ['type' => 'string'],
                            'explanation' => ['type' => 'string'],
                        ],
                        'required' => ['question', 'hint', 'answer', 'explanation'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['questions'],
            'additionalProperties' => false,
        ];
    }
}
