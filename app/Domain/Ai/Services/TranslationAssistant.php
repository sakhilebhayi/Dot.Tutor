<?php

namespace App\Domain\Ai\Services;

use App\Domain\Academic\Contracts\LanguageProfile;
use App\Domain\Academic\Enums\LanguageRole;
use App\Domain\Academic\Enums\ReviewStatus;
use App\Domain\Academic\Enums\TranslationProvenance;
use App\Domain\Academic\I18n\NotationGuard;
use App\Domain\Academic\Models\Translation;
use App\Domain\Academic\Translations;
use App\Domain\Ai\Guardrails\LearnerContextResolver;
use App\Domain\Ai\Guardrails\PromptBuilder;
use App\Domain\Ai\Guardrails\Refusal;
use App\Domain\Ai\Models\AiInteraction;
use App\Domain\Ai\Seams\LearningRecords;
use App\Domain\Ai\Support\AiOutcome;
use App\Domain\Ai\Support\AiPrompt;
use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\LibraryResource;
use App\Domain\Safety\Audit;
use App\Models\TutorProfile;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Machine translation of platform content (brief section 10) into the academic Translations store with provenance
 * "machine", the model that produced it and "not reviewed". The original always stays in its own column and is
 * shown side by side. A translation that loses a formula, number or LaTeX segment (academic NotationGuard) is
 * rejected and not stored. People who read the language mark it reviewed or rejected.
 */
class TranslationAssistant
{
    public function __construct(
        private readonly AiGateway $gateway,
        private readonly Translations $translations,
        private readonly LearnerContextResolver $contexts,
        private readonly LearningRecords $learning,
        private readonly LanguageProfile $languages,
    ) {}

    /** The model behind an alias and id, when the user may see it and the field is translatable. */
    public function resolve(User $user, string $alias, int $id, string $field): ?Model
    {
        if (! $this->translations->isTranslatableField($alias, $field)) {
            return null;
        }
        $model = $this->translations->resolve($alias, $id);

        return $model !== null && $this->canView($user, $model, $alias) ? $model : null;
    }

    public function canView(User $user, Model $model, string $alias): bool
    {
        return match (true) {
            $model instanceof Course => $this->learning->courseVisible($user, $model),
            $model instanceof LibraryResource => $this->learning->resourceVisible($user, $model),
            $model instanceof TutorProfile => $model->user_id === $user->id || $model->isApproved(),
            str_starts_with($alias, 'academic.') => (bool) $user->is_platform_operator,
            default => Gate::forUser($user)->allows('view', $model),
        };
    }

    /** @return array{interaction: ?AiInteraction, side_by_side: ?array<string, mixed>, reused: bool} */
    public function translate(User $user, Model $model, string $field, string $locale): array
    {
        $original = (string) $model->getAttribute($field);
        $from = $this->translations->originalLocale($model);
        $alias = $this->translations->aliasFor($model);

        $existing = $this->translations->get($model, $field, $locale);
        if ($existing !== null && $existing->review_status !== ReviewStatus::Rejected && ! $this->translations->isStale($existing, $model)) {
            return ['interaction' => null, 'side_by_side' => $this->translations->sideBySide($model, $field, $locale), 'reused' => true];
        }

        $preflight = match (true) {
            trim($original) === '' => new Refusal('nothing_to_translate'),
            $locale === $from => new Refusal('same_language'),
            mb_strlen($original) > (int) config('ai.translation_max_chars', 12000) => new Refusal('too_long'),
            default => null,
        };

        $context = $this->contexts->for($user, $locale);
        $fromName = LearnerContextResolver::languageName($from);
        $toName = LearnerContextResolver::languageName($locale);

        $interaction = $this->gateway->ask(
            $user, $context, 'translator', 'translate', "Translate {$alias} #{$model->getKey()} {$field} from {$from} to {$locale}",
            fn () => new AiPrompt(
                system: PromptBuilder::translator($fromName, $toName),
                blocks: [['type' => 'text', 'text' => "<source_text>\n{$original}\n</source_text>"]],
                tier: 'light',
                schema: [
                    'type' => 'object',
                    'properties' => ['translation' => ['type' => 'string']],
                    'required' => ['translation'],
                    'additionalProperties' => false,
                ],
            ),
            context: ['type' => $alias, 'id' => $model->getKey(), 'field' => $field, 'from' => $from, 'to' => $locale],
            preflight: $preflight,
            validate: function (AiOutcome $outcome) use ($original) {
                $translated = (string) ($outcome->data['translation'] ?? '');
                if (trim($translated) === '') {
                    return new Refusal('malformed_output');
                }
                $missing = NotationGuard::missing($original, $translated);

                return $missing === [] ? null : new Refusal('notation_lost', 'Changed or missing: '.implode(', ', array_slice($missing, 0, 8)).'.');
            },
        );

        if ($interaction->status !== 'ok') {
            return ['interaction' => $interaction, 'side_by_side' => null, 'reused' => false];
        }

        $this->translations->put($model, $field, $locale, (string) $interaction->output_data['translation'],
            TranslationProvenance::Machine, machineModel: (string) $interaction->model);

        return ['interaction' => $interaction, 'side_by_side' => $this->translations->sideBySide($model, $field, $locale), 'reused' => false];
    }

    /**
     * Reviewers: platform operators, or the content's owner when they hold the target language in their language
     * profile (they can read it). Nobody reviews a translation they cannot read.
     */
    public function review(User $reviewer, Translation $translation, string $decision): Translation
    {
        $model = $this->translations->resolve($translation->translatable_type, $translation->translatable_id);
        if ($model === null || ! $this->mayReview($reviewer, $model, $translation->locale)) {
            throw new AuthorizationException('You cannot review this translation.');
        }
        $status = ReviewStatus::tryFrom($decision);
        if ($status === null || $status === ReviewStatus::Unreviewed) {
            throw ValidationException::withMessages(['decision' => 'Choose approved or rejected.']);
        }
        $this->translations->review($translation, $reviewer, $status);
        Audit::record($reviewer, 'ai.translation.reviewed', $translation, [
            'decision' => $status->value, 'type' => $translation->translatable_type, 'locale' => $translation->locale,
            'provenance' => $translation->provenance->value,
        ]);

        return $translation->fresh();
    }

    public function mayReview(User $reviewer, Model $model, string $locale): bool
    {
        if ($reviewer->is_platform_operator) {
            return true;
        }
        $ownerId = $model->getAttribute('owner_id') ?? $model->getAttribute('user_id');
        if ((int) $ownerId !== $reviewer->id) {
            return false;
        }
        foreach ([LanguageRole::Interface, LanguageRole::Understands, LanguageRole::PrefersTeaching, LanguageRole::CanTeachIn] as $role) {
            if (in_array($locale, $this->languages->codes($reviewer, $role), true)) {
                return true;
            }
        }

        return false;
    }
}
