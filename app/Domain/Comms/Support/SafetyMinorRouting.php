<?php

namespace App\Domain\Comms\Support;

use App\Domain\Comms\Contracts\MinorRouting;
use App\Domain\Safety\Services\AgeResolver;
use App\Domain\Safety\Services\Guardians;
use App\Models\User;
use Illuminate\Support\Collection;

/** MinorRouting answered by the safety domain (the one place age bands and guardian links are decided). */
final class SafetyMinorRouting implements MinorRouting
{
    /** @var array<int, string> per-request memo: one notification burst asks about the same people repeatedly */
    private array $bands = [];

    public function __construct(private readonly AgeResolver $ages, private readonly Guardians $guardians) {}

    public function band(User $user): string
    {
        return $this->bands[$user->id] ??= $this->ages->band($user)->value;
    }

    public function isMinor(User $user): bool
    {
        return $this->band($user) !== 'adult';
    }

    public function guardiansOf(User $learner): Collection
    {
        return $this->guardians->guardiansOf($learner);
    }

    public function forget(): void
    {
        $this->bands = [];
    }
}
