<?php

namespace App\Domain\Marketplace\Seams;

use App\Domain\Marketplace\Contracts\LanguageProfilePort;
use App\Models\User;

/** No language data on this install yet: matching reports language fit as unknown. */
class NullLanguageProfile implements LanguageProfilePort
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function teachingLanguages(User $tutor): array
    {
        return [];
    }

    public function languageName(string $code): ?string
    {
        return null;
    }
}
