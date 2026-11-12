<?php

namespace App\Domain\Student\Services;

use App\Domain\Academic\Models\AcademicLevel;
use App\Domain\Academic\Models\LearnerProfile;
use App\Domain\Safety\Services\AccountStanding;
use App\Domain\Safety\Services\AgeResolver;
use App\Domain\Student\Models\StudentPreference;
use App\Domain\Student\Support\Stage;
use App\Models\User;

/**
 * Who is looking at a learner screen, in the terms the screens need: their academic level and stage, their age band
 * as far as it is known, whether a guardian must act first, and which reading step to use (D-1101).
 *
 * The step is decided here and nowhere else:
 *  - a person who chose "young" or "standard" in their account gets that;
 *  - otherwise a learner at a Foundation or Intermediate phase level (Grades 1 to 6 in CAPS and IEB) or a Cambridge
 *    Primary stage gets "young";
 *  - otherwise a learner with no level recorded whose known age band is "child" (under 13) gets "young";
 *  - everyone else gets "standard". Unknown age never switches it on: the step is a reading aid, not a safety rule,
 *    and the safety rules are enforced server-side whatever the screen looks like.
 */
class LearnerContext
{
    /** Stages whose learners get the young step by default. */
    public const YOUNG_STAGES = ['foundation', 'intermediate', 'cambridge-primary'];

    /** @var array<int, array<string, mixed>> */
    private array $cache = [];

    public function __construct(private readonly AgeResolver $ages, private readonly AccountStanding $standing) {}

    /**
     * @return array{profile: ?LearnerProfile, level: ?AcademicLevel, stage: ?string, stage_colour: ?string, onboarded: bool,
     *     band: ?string, band_label: ?string, minor: bool, limited: bool, suspended: bool, step: string, step_reason: string, step_preference: string}
     */
    public function for(User $user): array
    {
        if (isset($this->cache[$user->id])) {
            return $this->cache[$user->id];
        }

        $profile = LearnerProfile::query()->with(['level.stage', 'level.curriculum', 'subjects', 'country'])->where('user_id', $user->id)->first();
        $level = $profile?->level;
        $stage = $level?->stage?->code;
        $known = $this->ages->knownBand($user);
        $preference = StudentPreference::query()->where('user_id', $user->id)->value('step') ?? 'auto';

        [$step, $reason] = match (true) {
            $preference === 'young' => ['young', 'You chose the larger, simpler view.'],
            $preference === 'standard' => ['standard', 'You chose the standard view.'],
            $stage !== null && in_array($stage, self::YOUNG_STAGES, true) => ['young', 'Set from your level ('.$level->name.'). You can change it.'],
            $level === null && $known?->value === 'child' => ['young', 'Set from your age group. You can change it.'],
            default => ['standard', 'Standard view.'],
        };

        return $this->cache[$user->id] = [
            'profile' => $profile,
            'level' => $level,
            'stage' => $stage,
            'stage_colour' => Stage::colour($stage),
            'onboarded' => (bool) $profile?->isOnboarded(),
            'band' => $known?->value,
            'band_label' => $known?->label(),
            'minor' => $this->standing->isMinor($user),
            'limited' => $this->standing->isLimited($user),
            'suspended' => $this->standing->isSuspended($user),
            'step' => $step,
            'step_reason' => $reason,
            'step_preference' => $preference,
        ];
    }

    public function step(?User $user): string
    {
        return $user ? $this->for($user)['step'] : 'standard';
    }

    public function forget(User $user): void
    {
        unset($this->cache[$user->id]);
    }
}
