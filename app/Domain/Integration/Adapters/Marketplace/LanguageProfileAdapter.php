<?php

namespace App\Domain\Integration\Adapters\Marketplace;

use App\Domain\Academic\Contracts\LanguageProfile;
use App\Domain\Academic\Models\Language;
use App\Domain\Academic\Support\TeachingLanguage;
use App\Domain\Marketplace\Contracts\BatchLanguageLookup;
use App\Domain\Marketplace\Contracts\LanguageProfilePort;
use App\Models\User;

/** A tutor's TEACHING languages from the academic language profile (never the interface locale). */
class LanguageProfileAdapter implements BatchLanguageLookup, LanguageProfilePort
{
    /** @var array<int, list<TeachingLanguage>> teaching languages loaded by primeTeachingLanguages(), by user id */
    private array $primed = [];

    /** @var array<string, ?string> */
    private array $names = [];

    public function __construct(private readonly LanguageProfile $languages) {}

    public function primeTeachingLanguages(iterable $tutors): void
    {
        $ids = [];
        foreach ($tutors as $tutor) {
            $ids[] = (int) $tutor->id;
        }
        if ($ids === []) {
            return;
        }
        $loaded = $this->languages->teachingLanguagesFor($ids);
        foreach ($ids as $id) {
            $this->primed[$id] = $loaded[$id] ?? [];
        }
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function teachingLanguages(User $tutor): array
    {
        return array_map(fn (TeachingLanguage $l) => [
            'code' => $l->code,
            'name' => $l->nameEn,
            'level' => $l->proficiency->value,
            'verified' => $l->verified,
        ], $this->primed[(int) $tutor->id] ?? $this->languages->teachingLanguages($tutor));
    }

    public function languageName(string $code): ?string
    {
        return $this->names[$code] ??= Language::query()->where('code', $code)->value('name_en');
    }
}
