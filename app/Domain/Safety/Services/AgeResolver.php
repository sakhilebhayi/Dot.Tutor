<?php

namespace App\Domain\Safety\Services;

use App\Domain\Safety\Contracts\BirthDateSource;
use App\Domain\Safety\Enums\AgeBand;
use App\Domain\Safety\Models\AgeRecord;
use App\Domain\Safety\Models\GuardianLink;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * A person's age band. Order of trust: a date of birth the safety domain holds (operator-verified, then
 * guardian-confirmed, latest first), then the learner's self-declared date of birth (BirthDateSource).
 * Anyone with an active guardian link is a minor whatever else is known. Unknown follows
 * config('safety.unknown_age_policy').
 */
class AgeResolver
{
    public function __construct(private readonly BirthDateSource $source) {}

    public function birthDate(User $user): ?CarbonImmutable
    {
        return $this->lookup($user);
    }

    /** The band when a date of birth is known, else null. */
    public function knownBand(User $user): ?AgeBand
    {
        $birthDate = $this->birthDate($user);

        return $birthDate ? AgeBand::fromBirthDate($birthDate) : null;
    }

    /** The band every rule uses. */
    public function band(User $user): AgeBand
    {
        $known = $this->knownBand($user);

        if ($known !== null) {
            return $known;
        }

        if (GuardianLink::query()->active()->where('learner_id', $user->id)->exists()) {
            return AgeBand::Teen;
        }

        return config('safety.unknown_age_policy') === 'restrict' ? AgeBand::Child : AgeBand::Adult;
    }

    public function isMinor(User $user): bool
    {
        return $this->band($user)->isMinor();
    }

    private function lookup(User $user): ?CarbonImmutable
    {
        $record = AgeRecord::query()->where('user_id', $user->id)
            ->orderByRaw("case when source = 'operator_verified' then 0 else 1 end")
            ->orderByDesc('id')
            ->first();

        return $record?->birth_date ?? $this->source->birthDateFor($user);
    }
}
