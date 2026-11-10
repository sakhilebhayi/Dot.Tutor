<?php

namespace App\Domain\Academic\Contracts;

use App\Domain\Academic\Enums\LanguageRole;
use App\Domain\Academic\Support\LearnerLanguagePreferences;
use App\Domain\Academic\Support\TeachingLanguage;
use App\Models\User;

/**
 * Seam (architecture section 3): a person's languages by role. Matching uses
 * teachingLanguagesFor() on the tutor side and learnerPreferences() on the
 * learner side, never the interface locale. Owner: academic.
 */
interface LanguageProfile
{
    /**
     * BCP 47 codes the user holds in a role, in preference order.
     *
     * @return list<string>
     */
    public function codes(User|int $user, LanguageRole $role): array;

    /** The user's chosen interface locale, or null when they never chose one. */
    public function interfaceLocale(User|int $user): ?string;

    /**
     * Languages a tutor can teach in, with self-declared proficiency and the operator verification flag.
     *
     * @return list<TeachingLanguage>
     */
    public function teachingLanguages(User|int $user): array;

    /**
     * Batch form for matching: user id => teaching languages (users with none are absent).
     *
     * @param  list<int>  $userIds
     * @return array<int, list<TeachingLanguage>>
     */
    public function teachingLanguagesFor(array $userIds): array;

    /** What a learner wants to be taught in, what they understand, and whether bilingual teaching is fine. */
    public function learnerPreferences(User|int $user): LearnerLanguagePreferences;

    /**
     * Replace the user's languages in one role (not can_teach_in; see syncTeachingLanguages()).
     *
     * @param  list<string>  $codes
     */
    public function sync(User $user, LanguageRole $role, array $codes): void;

    /**
     * Replace a tutor's teaching languages. Operator verification survives only
     * where the language and the declared proficiency are unchanged.
     *
     * @param  list<array{code: string, proficiency: string}>  $entries
     */
    public function syncTeachingLanguages(User $user, array $entries): void;
}
