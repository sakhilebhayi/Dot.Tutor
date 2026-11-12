<?php

namespace App\Domain\Safety\Services;

use App\Domain\Safety\Contracts\BatchBirthDateSource;
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
    /** @var array<int, ?CarbonImmutable> birth dates read by prime(), by user id (list pages only, D-1604) */
    private array $primedBirthDates = [];

    /** @var array<int, bool> whether a primed user has an active guardian link */
    private array $primedLinked = [];

    public function __construct(private readonly BirthDateSource $source) {}

    /**
     * Read every listed person's date of birth and guardian link in three queries instead of several per person.
     * Only list pages call this, and only this instance answers from what it read; a person not primed is read
     * as before.
     *
     * @param  iterable<User>  $users
     */
    public function prime(iterable $users): void
    {
        $ids = [];
        foreach ($users as $user) {
            $ids[] = (int) $user->id;
        }
        if ($ids === []) {
            return;
        }

        $records = AgeRecord::query()->whereIn('user_id', $ids)
            ->orderByRaw("case when source = 'operator_verified' then 0 else 1 end")
            ->orderByDesc('id')
            ->get()
            ->unique('user_id')
            ->keyBy('user_id');
        $missing = array_values(array_diff($ids, $records->keys()->map(fn ($id) => (int) $id)->all()));
        $declared = $this->source instanceof BatchBirthDateSource && $missing !== [] ? $this->source->birthDatesFor($missing) : null;
        $linked = GuardianLink::query()->active()->whereIn('learner_id', $ids)->pluck('learner_id')->map(fn ($id) => (int) $id)->flip();

        foreach ($ids as $id) {
            if ($records->has($id)) {
                $this->primedBirthDates[$id] = $records[$id]->birth_date;
            } elseif ($declared !== null) {
                $this->primedBirthDates[$id] = $declared[$id] ?? null;
            }
            $this->primedLinked[$id] = $linked->has($id);
        }
    }

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

        $linked = $this->primedLinked[(int) $user->id] ?? GuardianLink::query()->active()->where('learner_id', $user->id)->exists();
        if ($linked) {
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
        if (array_key_exists((int) $user->id, $this->primedBirthDates)) {
            return $this->primedBirthDates[(int) $user->id];
        }

        $record = AgeRecord::query()->where('user_id', $user->id)
            ->orderByRaw("case when source = 'operator_verified' then 0 else 1 end")
            ->orderByDesc('id')
            ->first();

        return $record?->birth_date ?? $this->source->birthDateFor($user);
    }
}
