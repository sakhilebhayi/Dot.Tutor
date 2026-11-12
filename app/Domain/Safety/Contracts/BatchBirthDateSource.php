<?php

namespace App\Domain\Safety\Contracts;

use Carbon\CarbonImmutable;

/**
 * Optional companion to BirthDateSource (R-47 perf, D-1604): a source that can read many people's self-declared
 * dates of birth in one query. AgeResolver::prime() uses it for list pages; without it, lists read one at a time.
 */
interface BatchBirthDateSource
{
    /**
     * @param  list<int>  $userIds
     * @return array<int, ?CarbonImmutable> by user id; a missing id means no date is known
     */
    public function birthDatesFor(array $userIds): array;
}
