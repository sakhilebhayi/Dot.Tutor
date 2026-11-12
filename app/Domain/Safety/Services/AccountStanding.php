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
    /** @var array<int, bool> primed: a minor's processing consent is in force */
    private array $primedConsent = [];

    /** @var array<int, ?AccountRestriction> primed: the latest suspension in effect */
    private array $primedSuspension = [];

    public function __construct(private readonly AgeResolver $ages) {}

    /**
     * For list pages (ops people list, D-1604): read every listed person's age, guardian links, consents and
     * suspensions in a handful of queries; summary() then answers from them for those people.
     *
     * @param  iterable<User>  $users
     */
    public function prime(iterable $users): void
    {
        $list = collect($users);
        $ids = $list->map(fn (User $u) => (int) $u->id)->all();
        if ($ids === []) {
            return;
        }
        $this->ages->prime($list);

        $consenting = Consent::query()->inForce()->whereIn('learner_id', $ids)->where('kind', Consent::PROCESSING)
            ->whereIn('guardian_link_id', GuardianLink::query()->active()->whereIn('learner_id', $ids)->select('id'))
            ->pluck('learner_id')->map(fn ($id) => (int) $id)->flip();
        $suspensions = AccountRestriction::query()->inEffect()->whereIn('user_id', $ids)->where('kind', AccountRestriction::SUSPENSION)
            ->orderByDesc('id')->get()->unique('user_id')->keyBy('user_id');

        foreach ($ids as $id) {
            $this->primedConsent[$id] = $consenting->has($id);
            $this->primedSuspension[$id] = $suspensions->get($id);
        }
    }

    public function band(User $user): AgeBand
    {
        return $this->ages->band($user);
    }

    public function isMinor(User $user): bool
    {
        return $this->ages->isMinor($user);
    }

    public function hasGuardianConsent(User $learner): bool
    {
        if (array_key_exists((int) $learner->id, $this->primedConsent)) {
            return $this->primedConsent[(int) $learner->id];
        }

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
        if (array_key_exists((int) $user->id, $this->primedSuspension)) {
            return $this->primedSuspension[(int) $user->id];
        }

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
