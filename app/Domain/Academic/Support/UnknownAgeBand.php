<?php

namespace App\Domain\Academic\Support;

use App\Domain\Academic\Contracts\AgeBandResolver;
use Carbon\CarbonInterface;

/** Honest default: the academic domain does not decide age bands. */
class UnknownAgeBand implements AgeBandResolver
{
    public function bandFor(?CarbonInterface $birthDate): ?string
    {
        return null;
    }
}
