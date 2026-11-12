<?php

namespace App\Domain\Ai\Services;

use App\Domain\Academic\Models\AcademicLevel;
use App\Domain\Academic\Models\Subject;
use App\Domain\Ai\Guardrails\LearnerContext;
use App\Domain\Ai\Guardrails\LearnerContextResolver;
use App\Domain\Ai\Guardrails\PromptBuilder;
use App\Domain\Ai\Guardrails\Refusal;
use App\Domain\Ai\Models\AiInteraction;
use App\Domain\Ai\Models\AiQuestionDraft;
use App\Domain\Ai\Seams\LearningRecords;
use App\Domain\Ai\Support\AiPrompt;
use App\Domain\Learning\Enums\QuestionType;
use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\QuestionBank;
use App\Domain\Learning\Services\Marker;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tutor tasks (brief section 14, tutor assistance): lesson outline, quiz drafts into the tutor's own question bank
 * (drafts only, never published), revision exercises, course description, where their students struggle (from real
 * mastery data, aggregated, no names sent), and resource suggestions chosen only from the real library.
 */
class TutorAssistant
{
    public const TASKS = ['lesson_outline', 'quiz_drafts', 'revision_exercises', 'course_description', 'struggle_summary', 'resource_suggestions'];

    public function __construct(
        private readonly AiGateway $gateway,
        private readonly LearnerContextResolver $contexts,
        private readonly LearningRecords $learning,
        private readonly Marker $marker,
    ) {}

    /** @param  array<string, mixed>  $input */
    public function handle(User $tutor, string $task, array $input): AiInteraction
    {
        $context = $this->contexts->for($tutor, $input['language'] ?? null);
        $text = $this->gateway->minimise((string) ($input['text'] ?? ''));
        $academic = $this->academicLine($input['subject_id'] ?? null, $input['level_id'] ?? null);
        $request = "<tutor_request>\n".($text !== '' ? $text : '(no extra notes)')."\n</tutor_request>";
        $count = max(1, min(10, (int) ($input['count'] ?? 5)));
        $meta = array_filter(['subject_id' => $input['subject_id'] ?? null, 'level_id' => $input['level_id'] ?? null]);

        $simple = fn (string $instruction, string $tier = 'standard') => fn () => new AiPrompt(
            system: PromptBuilder::tutor($instruction, $academic, $context->languageName, $context->language),
            blocks: [['type' => 'text', 'text' => $request]],
            tier: $tier,
        );

        return match ($task) {
            'lesson_outline' => $this->gateway->ask($tutor, $context, 'tutor', $task, $text, $simple(
                'Write a lesson outline for about '.max(15, min(240, (int) ($input['duration_minutes'] ?? 60))).' minutes on the topic in the request: learning objectives, a starter, the main teaching steps with timings, a check for understanding, practice, and a short exit question. Note common misconceptions to watch for.'
            ), $meta),
            'revision_exercises' => $this->gateway->ask($tutor, $context, 'tutor', $task, $text, $simple(
                "Write {$count} revision exercises on the topic in the request, from easier to harder, each with a worked answer the tutor can check. Put all answers in a separate section after the exercises."
            ), $meta),
            'quiz_drafts' => $this->quizDrafts($tutor, $context, (int) ($input['bank_id'] ?? 0), $count, $text, $input),
            'course_description' => $this->courseDescription($tutor, $context, (int) ($input['course_id'] ?? 0), $text),
            'struggle_summary' => $this->struggleSummary($tutor, $context),
            'resource_suggestions' => $this->resourceSuggestions($tutor, $context, $text, $input, $request, $academic),
        };
    }

    /** @param  array<string, mixed>  $input */
    private function quizDrafts(User $tutor, LearnerContext $context, int $bankId, int $count, string $text, array $input): AiInteraction
    {
        $bank = QuestionBank::query()->find($bankId);
        $preflight = $bank === null || $bank->owner_id !== $tutor->id ? new Refusal('not_your_bank') : null;
        $types = array_values(array_intersect((array) ($input['types'] ?? []), QuestionType::values())) ?: ['single_choice', 'numeric', 'short_text'];
        $difficulty = in_array($input['difficulty'] ?? null, ['introductory', 'standard', 'advanced'], true) ? $input['difficulty'] : 'standard';

        $build = function () use ($bank, $count, $types, $difficulty, $text, $context, $input) {
            $academic = $this->academicLine($bank->subject_id, $input['level_id'] ?? null);
            $instruction = "Draft {$count} quiz questions for the question bank \"{$bank->title}\" (topic: {$bank->topic}".($bank->strand ? ", strand: {$bank->strand}" : '')."), difficulty {$difficulty}. "
                .'Use only these question types: '.implode(', ', $types).'. '
                .'single_choice: 3 to 5 options with keys A, B, C...; correct_keys holds exactly one key. multi_select: 3 to 6 options; correct_keys holds every correct key. '
                .'numeric: numeric_answer holds the number as digits with a point as decimal separator, tolerance the allowed difference (0 for exact); options empty. '
                .'short_text: accepted_answers lists every acceptable short answer; options empty. Leave fields that do not apply empty. '
                .'Each question needs a short explanation of the answer for the tutor. The tutor reviews each draft before it can be used.';

            return new AiPrompt(
                system: PromptBuilder::tutor($instruction, $academic, $context->languageName, $context->language),
                blocks: [['type' => 'text', 'text' => "<tutor_request>\n".($text !== '' ? $text : 'Cover the main ideas of the topic.')."\n</tutor_request>"]],
                schema: self::quizSchema(),
            );
        };

        $interaction = $this->gateway->ask($tutor, $context, 'tutor', 'quiz_drafts', $text, $build,
            ['bank_id' => $bankId, 'count' => $count, 'subject_id' => $bank?->subject_id], preflight: $preflight);

        if ($interaction->status === 'ok' && is_array($interaction->output_data)) {
            [$saved, $skipped] = $this->storeDrafts($interaction, $bank, $tutor, $interaction->output_data['questions'] ?? []);
            $interaction->forceFill(['context' => $interaction->context + ['drafts_saved' => $saved, 'drafts_skipped' => $skipped]])->save();
        }

        return $interaction;
    }

    /**
     * Keep only drafts whose answer key passes learning's own key validation. @param  list<array<string, mixed>>  $questions
     *
     * @return array{0: int, 1: int}
     */
    private function storeDrafts(AiInteraction $interaction, QuestionBank $bank, User $tutor, array $questions): array
    {
        $saved = 0;
        $skipped = 0;
        foreach (array_slice($questions, 0, 10) as $q) {
            $type = QuestionType::tryFrom((string) ($q['type'] ?? ''));
            $prompt = trim((string) ($q['prompt'] ?? ''));
            if ($type === null || $prompt === '') {
                $skipped++;

                continue;
            }
            $answer = match ($type) {
                QuestionType::SingleChoice => ['key' => (string) ($q['correct_keys'][0] ?? '')],
                QuestionType::MultiSelect => ['keys' => array_map('strval', (array) ($q['correct_keys'] ?? []))],
                QuestionType::Numeric => ['value' => $q['numeric_answer'] ?? null, 'tolerance' => ($q['tolerance'] ?? '') === '' ? 0 : $q['tolerance']],
                QuestionType::ShortText => ['accepted' => (array) ($q['accepted_answers'] ?? [])],
            };
            try {
                $key = $this->marker->normaliseKey($type, $q['options'] ?? null, $answer);
            } catch (ValidationException) {
                $skipped++;

                continue;
            }
            AiQuestionDraft::create([
                'interaction_id' => $interaction->id,
                'owner_id' => $tutor->id,
                'bank_id' => $bank->id,
                'type' => $type->value,
                'prompt' => mb_substr($prompt, 0, 5000),
                'options' => $key['options'],
                'answer' => $key['answer'],
                'explanation' => mb_substr((string) ($q['explanation'] ?? ''), 0, 5000) ?: null,
                'topic' => $bank->topic,
                'difficulty' => in_array($q['difficulty'] ?? null, ['introductory', 'standard', 'advanced'], true) ? $q['difficulty'] : null,
                'marks' => 1,
                'status' => 'draft',
            ]);
            $saved++;
        }

        return [$saved, $skipped];
    }

    /**
     * Accept a draft into the tutor's bank (optionally edited first), through learning's key validation.
     *
     * @param  array<string, mixed>  $edits
     */
    public function acceptDraft(User $tutor, AiQuestionDraft $draft, array $edits = []): AiQuestionDraft
    {
        abort_unless($draft->owner_id === $tutor->id && $draft->bank?->owner_id === $tutor->id, 404);
        if ($draft->status !== 'draft') {
            throw ValidationException::withMessages(['draft' => 'This draft was already '.$draft->status.'.']);
        }
        $type = QuestionType::from($draft->type);
        $key = $this->marker->normaliseKey($type, $edits['options'] ?? $draft->options, $edits['answer'] ?? $draft->answer);

        return DB::transaction(function () use ($draft, $edits, $key) {
            $question = $draft->bank->questions()->create([
                'type' => $draft->type,
                'prompt' => $edits['prompt'] ?? $draft->prompt,
                'options' => $key['options'],
                'answer' => $key['answer'],
                'explanation' => array_key_exists('explanation', $edits) ? $edits['explanation'] : $draft->explanation,
                'topic' => $draft->topic,
                'marks' => (int) ($edits['marks'] ?? $draft->marks),
                'difficulty' => $draft->difficulty,
            ]);
            $draft->forceFill(['status' => 'accepted', 'question_id' => $question->id, 'decided_at' => now()])->save();

            return $draft;
        });
    }

    public function discardDraft(User $tutor, AiQuestionDraft $draft): AiQuestionDraft
    {
        abort_unless($draft->owner_id === $tutor->id, 404);
        if ($draft->status === 'draft') {
            $draft->forceFill(['status' => 'discarded', 'decided_at' => now()])->save();
        }

        return $draft;
    }

    private function courseDescription(User $tutor, LearnerContext $context, int $courseId, string $text): AiInteraction
    {
        $course = Course::query()->with(['sections.lessons'])->find($courseId);
        $preflight = $course === null || $course->owner_id !== $tutor->id ? new Refusal('not_your_course') : null;

        $build = function () use ($course, $context, $text) {
            $outline = $course->sections->sortBy('position')->map(fn ($section) => '- '.$section->title.': '
                .$section->lessons->sortBy('position')->pluck('title')->implode('; '))->implode("\n");
            $facts = implode("\n", array_filter([
                'Title: '.$course->title,
                $course->description ? 'Current description: '.$course->description : null,
                $course->objectives ? 'Objectives: '.implode('; ', (array) $course->objectives) : null,
                $course->prerequisites ? 'Prerequisites: '.implode('; ', (array) $course->prerequisites) : null,
                $course->workload_minutes ? 'Workload: about '.$course->workload_minutes.' minutes' : null,
                "Outline:\n".($outline ?: '(no sections yet)'),
            ]));

            return new AiPrompt(
                system: PromptBuilder::tutor('Suggest a clear, honest course description (120 to 200 words) from the course facts given: who it is for, what learners will be able to do, and how it is organised. Do not promise results, grades, accreditation or anything the facts do not support. The tutor decides whether to use it.',
                    $this->academicLine($course->subject_id, $course->level_id), $context->languageName, $context->language),
                blocks: [['type' => 'text', 'text' => $facts."\n\n<tutor_request>\n".($text !== '' ? $text : '(no extra notes)')."\n</tutor_request>"]],
            );
        };

        return $this->gateway->ask($tutor, $context, 'tutor', 'course_description', $text !== '' ? $text : 'Describe course '.$courseId, $build,
            ['course_id' => $courseId, 'subject_id' => $course?->subject_id], preflight: $preflight);
    }

    private function struggleSummary(User $tutor, LearnerContext $context): AiInteraction
    {
        $aggregate = $this->aggregateMastery($tutor);
        $preflight = $aggregate['topics'] === [] ? new Refusal('no_evidence') : null;

        $build = function () use ($aggregate, $context) {
            $lines = array_map(fn (array $t) => "- {$t['topic']}: {$t['learners']} learner(s) with evidence; needs attention {$t['needs_attention']}, developing {$t['developing']}, secure {$t['secure']}; declining trend {$t['declining']}",
                array_slice($aggregate['topics'], 0, 40));

            return new AiPrompt(
                system: PromptBuilder::tutor('Summarise for the tutor where their learners are struggling, using only the measured topic data given (computed from marked answers on Dot.Tutor). Name the two or three topics that most need attention and why the data says so, and suggest what to reteach first. Do not invent learners, scores or causes; say when the evidence is thin (few learners or answers).',
                    null, $context->languageName, $context->language),
                blocks: [['type' => 'text', 'text' => "Learners with marked answers: {$aggregate['learners']}\n".implode("\n", $lines)]],
            );
        };

        return $this->gateway->ask($tutor, $context, 'tutor', 'struggle_summary', 'Where are my students struggling?', $build,
            ['learners' => $aggregate['learners'], 'topics' => count($aggregate['topics'])], preflight: $preflight);
    }

    /**
     * Topic mastery across the tutor's learners, counted (no names, no per-learner rows).
     *
     * @return array{learners: int, topics: list<array{topic: string, subject_id: ?int, learners: int, needs_attention: int, developing: int, secure: int, declining: int}>}
     */
    public function aggregateMastery(User $tutor): array
    {
        $topics = [];
        $withEvidence = 0;
        foreach (User::query()->whereIn('id', $this->learning->studentIdsOf($tutor))->get() as $student) {
            $rows = array_filter($this->learning->mastery($student), fn (array $r) => $r['level'] !== 'not_enough_evidence');
            if ($rows !== []) {
                $withEvidence++;
            }
            foreach ($rows as $row) {
                $key = ($row['subject_id'] ?? 0).'|'.mb_strtolower($row['topic']);
                $topics[$key] ??= ['topic' => $row['topic'], 'subject_id' => $row['subject_id'], 'learners' => 0, 'needs_attention' => 0, 'developing' => 0, 'secure' => 0, 'declining' => 0];
                $topics[$key]['learners']++;
                $topics[$key][$row['level']] = ($topics[$key][$row['level']] ?? 0) + 1;
                if ($row['trend'] === 'declining') {
                    $topics[$key]['declining']++;
                }
            }
        }
        $topics = array_values($topics);
        usort($topics, fn ($a, $b) => [$b['needs_attention'], $b['declining'], $a['topic']] <=> [$a['needs_attention'], $a['declining'], $b['topic']]);

        return ['learners' => $withEvidence, 'topics' => $topics];
    }

    /** @param  array<string, mixed>  $input */
    private function resourceSuggestions(User $tutor, LearnerContext $context, string $text, array $input, string $request, ?string $academic): AiInteraction
    {
        $subjectId = isset($input['subject_id']) ? (int) $input['subject_id'] : null;
        $levelId = isset($input['level_id']) ? (int) $input['level_id'] : null;
        $candidates = $this->learning->libraryCandidates($subjectId, $levelId, $text !== '' ? $text : null);
        $ids = array_column($candidates, 'id');
        $preflight = $candidates === [] ? new Refusal('no_candidates') : null;

        $build = fn () => new AiPrompt(
            system: PromptBuilder::tutor('From the library resources listed (and only those), choose up to five that best fit the tutor\'s request and say in one sentence each why. Use the resource ids exactly as listed. If none fit, return an empty list.', $academic, $context->languageName, $context->language),
            blocks: [['type' => 'text', 'text' => "Library resources (metadata from Dot.Tutor):\n".json_encode($candidates, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n".$request]],
            schema: [
                'type' => 'object',
                'properties' => ['suggestions' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'properties' => ['resource_id' => ['type' => 'integer'], 'why' => ['type' => 'string']],
                    'required' => ['resource_id', 'why'],
                    'additionalProperties' => false,
                ]]],
                'required' => ['suggestions'],
                'additionalProperties' => false,
            ],
        );

        $interaction = $this->gateway->ask($tutor, $context, 'tutor', 'resource_suggestions', $text, $build,
            ['subject_id' => $subjectId, 'level_id' => $levelId, 'candidates' => count($candidates)], preflight: $preflight);

        if ($interaction->status === 'ok' && is_array($interaction->output_data)) {
            // Only resources that really exist in the candidate list survive (no invented resources).
            $byId = collect($candidates)->keyBy('id');
            $kept = collect($interaction->output_data['suggestions'] ?? [])
                ->filter(fn ($s) => is_array($s) && in_array((int) ($s['resource_id'] ?? 0), $ids, true))
                ->unique('resource_id')->take(5)
                ->map(fn ($s) => ['resource_id' => (int) $s['resource_id'], 'title' => $byId[(int) $s['resource_id']]['title'], 'why' => (string) ($s['why'] ?? ''),
                    'url' => route('learning.library.show', (int) $s['resource_id'])])
                ->values()->all();
            $interaction->forceFill(['output_data' => ['suggestions' => $kept]])->save();
        }

        return $interaction;
    }

    private function academicLine(mixed $subjectId, mixed $levelId): ?string
    {
        $subject = $subjectId ? Subject::query()->find((int) $subjectId) : null;
        $level = $levelId ? AcademicLevel::query()->with(['curriculum', 'stage'])->find((int) $levelId) : null;
        if ($subject === null && $level === null) {
            return null;
        }

        return 'Subject: '.($subject?->name ?? 'as in the request').'; curriculum and level: '
            .($level ? $level->curriculum?->label().', '.$level->name : 'as in the request').'.';
    }

    /** @return array<string, mixed> */
    public static function quizSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'questions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'type' => ['type' => 'string', 'enum' => QuestionType::values()],
                            'prompt' => ['type' => 'string'],
                            'options' => ['type' => 'array', 'items' => [
                                'type' => 'object',
                                'properties' => ['key' => ['type' => 'string'], 'text' => ['type' => 'string']],
                                'required' => ['key', 'text'],
                                'additionalProperties' => false,
                            ]],
                            'correct_keys' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'numeric_answer' => ['type' => 'string'],
                            'tolerance' => ['type' => 'string'],
                            'accepted_answers' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'explanation' => ['type' => 'string'],
                            'difficulty' => ['type' => 'string', 'enum' => ['introductory', 'standard', 'advanced']],
                        ],
                        'required' => ['type', 'prompt', 'options', 'correct_keys', 'numeric_answer', 'tolerance', 'accepted_answers', 'explanation', 'difficulty'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['questions'],
            'additionalProperties' => false,
        ];
    }
}
