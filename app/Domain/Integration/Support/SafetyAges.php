<?php

namespace App\Domain\Integration\Support;

use App\Domain\Safety\Enums\AgeBand;
use App\Domain\Safety\Models\GuardianLink;
use App\Domain\Safety\Services\AgeResolver;
use App\Models\User;

/**
 * The age band other domains see: the safety domain's band when a date of birth is known (safety records first,
 * then the academic learner profile), a minor (teen) when a guardian is linked, otherwise unknown (null). Callers
 * that need a decision for unknown ages ask the safety gate, which applies safety.unknown_age_policy.
 */
class SafetyAges
{
    public function __construct(private readonly AgeResolver $ages) {}

    public function knownBand(User $user): ?AgeBand
    {
        $band = $this->ages->knownBand($user);

        if ($band !== null) {
            return $band;
        }

        return GuardianLink::query()->active()->where('learner_id', $user->id)->exists() ? AgeBand::Teen : null;
    }
}
