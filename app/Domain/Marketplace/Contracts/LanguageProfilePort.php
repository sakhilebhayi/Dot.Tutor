<?php

namespace App\Domain\Marketplace\Contracts;

use App\Models\User;

/**
 * A person's TEACHING languages (role can_teach_in), never the interface
 * locale. Owned by the academic agent as Academic\Contracts\LanguageProfile;
 * the integration step binds an adapter onto it (decision D-302).
 */
interface LanguageProfilePort
{
    public function isAvailable(): bool;

    /**
     * @return list<array{code: string, name: string, level: ?string, verified: bool}>
     */
    public function teachingLanguages(User $tutor): array;

    public function languageName(string $code): ?string;
}
