<?php

namespace App\Domain\Safety\Services;

use App\Domain\Safety\Contracts\BirthDateSource;
use App\Domain\Safety\Enums\AgeBand;
use App\Domain\Safety\Models\AgeRecord;
use App\Domain\Safety\Models\GuardianLink;
use App\Enums\TutorProfileStatus;
use App\Models\TutorProfile;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * A person's age band. Order of trust: a date of birth the safety domain holds (operator-verified, then
 * guardian-confirmed, latest first), then the learner's self-declared date of birth (BirthDateSource).
 * Anyone with an active guardian link is a minor whatever else is known. Unknown follows
 * config('safety.unknown_age_policy'): 'ask' (the default) protects an unattested unknown as a child until a date
 * of birth is declared, 'restrict' treats every unknown as a child, 'adult' treats every unknown as an adult.
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

        return match (config('safety.unknown_age_policy')) {
            'adult' => AgeBand::Adult,
            'restrict' => AgeBand::Child,
            default => $this->attestedAdult($user) ? AgeBand::Adult : AgeBand::Child,
        };
    }

    /**
     * Someone of unknown age counts as an adult only when there is more than their silence to go on (R-47 security
     * S-03): an operator, a tutor whose application an operator approved, a person with a cleared safeguarding
     * screening, or an active guardian of a learner (the invitation refused anyone known to be a minor). Everyone
     * else is protected as a child until they declare a month and year of birth.
     */
    public function attestedAdult(User $user): bool
    {
        if ($user->is_platform_operator) {
            return true;
        }

        if (TutorProfile::query()->where('user_id', $user->id)->where('status', TutorProfileStatus::Approved->value)->exists()) {
            return true;
        }

        if (GuardianLink::query()->active()->where('guardian_id', $user->id)->exists()) {
            return true;
        }

        return app(Screening::class)->isClearedForMinors($user);
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
