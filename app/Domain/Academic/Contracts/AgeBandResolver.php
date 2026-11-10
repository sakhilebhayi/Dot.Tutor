<?php

namespace App\Domain\Academic\Contracts;

use Carbon\CarbonInterface;

/**
 * Hands age banding to the safety domain (App\Domain\Safety\AgeBand owns the
 * rule: child under 13, teen 13 to 17, adult 18+). The academic domain only
 * supplies the conservative birth date (last day of the stored birth month).
 * The default binding answers null ("unknown") until the integration provider
 * binds the safety implementation; callers must treat unknown as the most
 * protected band.
 */
interface AgeBandResolver
{
    /** 'child' | 'teen' | 'adult', or null when unknown. */
    public function bandFor(?CarbonInterface $birthDate): ?string;
}
