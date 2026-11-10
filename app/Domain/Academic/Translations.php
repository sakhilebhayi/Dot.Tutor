<?php

namespace App\Domain\Academic;

use App\Domain\Academic\Enums\ReviewStatus;
use App\Domain\Academic\Enums\TranslationProvenance;
use App\Domain\Academic\I18n\NotationGuard;
use App\Domain\Academic\Models\Language;
use App\Domain\Academic\Models\Translation;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Translated content with provenance (architecture section 2). The original
 * stays in the owning model's column, in its `original_locale` (English when
 * the model has no such attribute). Each domain registers the models and the
 * fields that may be translated:
 *
 *     app(Translations::class)->register('learning.course', Course::class, ['title', 'summary']);
 *
 * The alias, not the class name, is stored, so a class can move without
 * orphaning its translations. Machine translations must name their model and
 * start unreviewed; the side-by-side view says so.
 */
class Translations
{
    /** @var array<string, array{class: class-string<Model>, fields: list<string>}> */
    private array $types = [];

    /**
     * @param  class-string<Model>  $class
     * @param  list<string>  $fields
     */
    public function register(string $alias, string $class, array $fields): void
    {
        $this->types[$alias] = ['class' => $class, 'fields' => $fields];
    }

    /** @return array<string, array{class: class-string<Model>, fields: list<string>}> */
    public function types(): array
    {
        return $this->types;
    }

    public function aliasFor(Model $model): string
    {
        foreach ($this->types as $alias => $type) {
            if ($model instanceof $type['class']) {
                return $alias;
            }
        }

        throw new InvalidArgumentException($model::class.' is not registered as translatable.');
    }

    public function resolve(string $alias, int|string $id): ?Model
    {
        $class = $this->types[$alias]['class'] ?? null;

        return $class ? $class::query()->find($id) : null;
    }

    public function isTranslatableField(string $alias, string $field): bool
    {
        return in_array($field, $this->types[$alias]['fields'] ?? [], true);
    }

    public function originalLocale(Model $model): string
    {
        return (string) ($model->getAttribute('original_locale') ?: config('app.fallback_locale', 'en'));
    }

    public function put(
        Model $model,
        string $field,
        string $locale,
        string $body,
        TranslationProvenance $provenance,
        ?User $translator = null,
        ?string $translatorLabel = null,
        ?string $machineModel = null,
    ): Translation {
        $alias = $this->aliasFor($model);

        if (! $this->isTranslatableField($alias, $field)) {
            throw new InvalidArgumentException("[{$field}] is not a translatable field of [{$alias}].");
        }
        if (! Language::query()->active()->where('code', $locale)->where('modality', 'written')->exists()) {
            throw new InvalidArgumentException("Unknown or inactive written language [{$locale}].");
        }
        if ($locale === $this->originalLocale($model)) {
            throw new InvalidArgumentException('A translation cannot be in the original language.');
        }
        if (trim($body) === '') {
            throw new InvalidArgumentException('A translation cannot be empty.');
        }
        if ($provenance === TranslationProvenance::Machine && blank($machineModel)) {
            throw new InvalidArgumentException('A machine translation must name the model that produced it.');
        }
        if ($provenance !== TranslationProvenance::Machine && ! $translator && blank($translatorLabel)) {
            throw new InvalidArgumentException('An author or human translation must name its translator.');
        }

        $translation = Translation::query()->firstOrNew([
            'translatable_type' => $alias,
            'translatable_id' => $model->getKey(),
            'field' => $field,
            'locale' => $locale,
        ]);

        $translation->fill([
            'body' => $body,
            'provenance' => $provenance,
            'translator_id' => $provenance === TranslationProvenance::Machine ? null : $translator?->id,
            'translator_label' => $provenance === TranslationProvenance::Machine ? null : $translatorLabel,
            'machine_model' => $provenance === TranslationProvenance::Machine ? $machineModel : null,
            'source_hash' => $this->hash((string) $model->getAttribute($field)),
        ]);

        // A new text is a new claim: any earlier review does not cover it.
        $translation->forceFill(['review_status' => ReviewStatus::Unreviewed, 'reviewed_by_id' => null, 'reviewed_at' => null]);
        $translation->save();

        return $translation;
    }

    public function get(Model $model, string $field, string $locale): ?Translation
    {
        return Translation::query()
            ->where('translatable_type', $this->aliasFor($model))
            ->where('translatable_id', $model->getKey())
            ->where('field', $field)
            ->where('locale', $locale)
            ->first();
    }

    /** @return list<string> */
    public function availableLocales(Model $model, string $field): array
    {
        return Translation::query()
            ->where('translatable_type', $this->aliasFor($model))
            ->where('translatable_id', $model->getKey())
            ->where('field', $field)
            ->where('review_status', '!=', ReviewStatus::Rejected->value)
            ->orderBy('locale')
            ->pluck('locale')
            ->all();
    }

    public function review(Translation $translation, User $reviewer, ReviewStatus $status): Translation
    {
        if ($status === ReviewStatus::Unreviewed) {
            throw new InvalidArgumentException('A review must approve or reject.');
        }

        $translation->forceFill(['review_status' => $status, 'reviewed_by_id' => $reviewer->id, 'reviewed_at' => now()])->save();

        return $translation;
    }

    public function isStale(Translation $translation, Model $model): bool
    {
        return ! hash_equals($translation->source_hash, $this->hash((string) $model->getAttribute($translation->field)));
    }

    /**
     * The original and (if any, and not rejected) the translation, side by side.
     *
     * @return array{type: string, id: int|string, field: string, original: array{locale: string, text: string}, translation: ?array<string, mixed>, available_locales: list<string>}
     */
    public function sideBySide(Model $model, string $field, string $locale): array
    {
        $alias = $this->aliasFor($model);
        $original = (string) $model->getAttribute($field);
        $translation = $this->get($model, $field, $locale);

        if ($translation?->review_status === ReviewStatus::Rejected) {
            $translation = null;
        }

        return [
            'type' => $alias,
            'id' => $model->getKey(),
            'field' => $field,
            'original' => ['locale' => $this->originalLocale($model), 'text' => $original],
            'translation' => $translation ? [
                'locale' => $translation->locale,
                'text' => $translation->body,
                'provenance' => $translation->provenance->value,
                'provenance_label' => $translation->provenance->label(),
                'machine_model' => $translation->machine_model,
                'translator' => $translation->translator?->name ?? $translation->translator_label,
                'review_status' => $translation->review_status->value,
                'review_label' => $translation->review_status->label(),
                'out_of_date' => $this->isStale($translation, $model),
                'notation_missing' => NotationGuard::missing($original, $translation->body),
                'translated_at' => $translation->updated_at?->toIso8601String(),
            ] : null,
            'available_locales' => $this->availableLocales($model, $field),
        ];
    }

    private function hash(string $text): string
    {
        return hash('sha256', $text);
    }
}
