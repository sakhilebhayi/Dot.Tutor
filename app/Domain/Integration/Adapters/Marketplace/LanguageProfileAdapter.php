<?php

namespace App\Domain\Integration\Adapters\Marketplace;

use App\Domain\Academic\Contracts\LanguageProfile;
use App\Domain\Academic\Models\Language;
use App\Domain\Academic\Support\TeachingLanguage;
use App\Domain\Marketplace\Contracts\LanguageProfilePort;
use App\Models\User;

/** A tutor's TEACHING languages from the academic language profile (never the interface locale). */
class LanguageProfileAdapter implements LanguageProfilePort
{
    public function __construct(private readonly LanguageProfile $languages) {}

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
        ], $this->languages->teachingLanguages($tutor));
    }

    public function languageName(string $code): ?string
    {
        return Language::query()->where('code', $code)->value('name_en');
    }
}
