<?php

namespace App\Domain\Marketplace\Contracts;

use App\Models\User;

/**
 * Optional companion to LanguageProfilePort (R-47 perf, D-1602): an adapter that can load many tutors' teaching
 * languages in one query implements this; matching calls it once before scoring, and teachingLanguages() then answers
 * from what was loaded. An adapter without it keeps working one tutor at a time.
 */
interface BatchLanguageLookup
{
    /** @param  iterable<User>  $tutors */
    public function primeTeachingLanguages(iterable $tutors): void;
}
