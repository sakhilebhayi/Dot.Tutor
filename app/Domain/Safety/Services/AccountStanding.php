<?php

namespace App\Domain\Safety\Services;

use App\Domain\Safety\Enums\AgeBand;
use App\Domain\Safety\Models\AccountRestriction;
use App\Domain\Safety\Models\Consent;
use App\Domain\Safety\Models\GuardianLink;
use App\Models\User;

/**
 * Where an account stands for safeguarding: its age band, whether a minor's account is limited (no guardian
 * has linked and consented yet, POPIA s35(1)(a)), and whether it is suspended.
 */
class AccountStanding
{
    public function __construct(private readonly AgeResolver $ages) {}

    public function band(User $user): AgeBand
    {
        return $this->ages->band($user);
    }

    public function isMinor(User $user): bool
    {
        return $this->ages->isMinor($user);
    }

    /** No date of birth is known (the band then follows safety.unknown_age_policy). */
    public function ageUnknown(User $user): bool
    {
        return $this->ages->knownBand($user) === null;
    }

    public function hasGuardianConsent(User $learner): bool
    {
        return Consent::query()->inForce()
            ->where('learner_id', $learner->id)
            ->where('kind', Consent::PROCESSING)
            ->whereIn('guardian_link_id', GuardianLink::query()->active()->where('learner_id', $learner->id)->select('id'))
            ->exists();
    }

    /** A minor without a consenting, linked guardian may only invite a guardian, read, report and ask support. */
    public function isLimited(User $user): bool
    {
        return $this->isMinor($user) && ! $this->hasGuardianConsent($user);
    }

    public function activeSuspension(User $user): ?AccountRestriction
    {
        return AccountRestriction::query()->inEffect()
            ->where('user_id', $user->id)->where('kind', AccountRestriction::SUSPENSION)
            ->orderByDesc('id')->first();
    }

    public function isSuspended(User $user): bool
    {
        return $this->activeSuspension($user) !== null;
    }

    public function isOperator(User $user): bool
    {
        return (bool) $user->is_platform_operator;
    }

    /** @return array<string, mixed> */
    public function summary(User $user): array
    {
        $band = $this->band($user);
        $suspension = $this->activeSuspension($user);

        return [
            'age_band' => $band->value,
            'age_known' => $this->ages->knownBand($user) !== null,
            'is_minor' => $band->isMinor(),
            'guardian_consent' => $band->isMinor() ? $this->hasGuardianConsent($user) : null,
            'status' => match (true) {
                $suspension !== null => 'suspended',
                $this->isLimited($user) => 'limited',
                default => 'active',
            },
            'suspended_until' => $suspension?->ends_at?->toIso8601String(),
        ];
    }
}
