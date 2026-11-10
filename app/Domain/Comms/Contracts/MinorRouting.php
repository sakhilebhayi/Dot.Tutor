<?php

namespace App\Domain\Comms\Contracts;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * What comms needs to know about minors: a person's age band and their linked guardians. Default binding
 * (bindIf): SafetyMinorRouting over the safety domain's AgeResolver and Guardians, so the safety domain's rules
 * (unknown-age policy, an active guardian link makes someone a minor) decide.
 */
interface MinorRouting
{
    /** 'child' (under 13), 'teen' (13 to 17) or 'adult'. */
    public function band(User $user): string;

    public function isMinor(User $user): bool;

    /** @return Collection<int, User> the learner's linked, active guardians */
    public function guardiansOf(User $learner): Collection;
}
