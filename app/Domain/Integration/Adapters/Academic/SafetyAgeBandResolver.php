<?php

namespace App\Domain\Integration\Adapters\Academic;

use App\Domain\Academic\Contracts\AgeBandResolver;
use App\Domain\Safety\Enums\AgeBand;
use Carbon\CarbonInterface;

/** Academic's age-band question answered by the safety rule (child under 13, teen 13 to 17, adult 18+). */
class SafetyAgeBandResolver implements AgeBandResolver
{
    public function bandFor(?CarbonInterface $birthDate): ?string
    {
        return $birthDate === null ? null : AgeBand::fromBirthDate($birthDate)->value;
    }
}
