<?php

namespace App\Domain\Academic\Services;

use App\Domain\Academic\Contracts\AgeBandResolver;
use App\Domain\Academic\Contracts\LanguageProfile;
use App\Domain\Academic\Enums\LanguageRole;
use App\Domain\Academic\Models\AcademicLevel;
use App\Domain\Academic\Models\LearnerProfile;
use App\Domain\Academic\Models\SubjectOffering;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Writes a learner profile from validated input. Minimisation: the birth date
 * is stored as a month; an institution is kept only at tertiary levels; no
 * school name is ever stored.
 */
class LearnerProfiles
{
    public function __construct(private readonly LanguageProfile $languages, private readonly AgeBandResolver $ageBands) {}

    /**
     * @param  array{country_id: int|string, academic_level_id: int|string, subjects?: list<int|string>, institution_id?: int|string|null, birth_year: int|string, birth_month: int|string, teaching?: list<string>, understands?: list<string>, learning?: list<string>, bilingual_ok?: string|null}  $data
     */
    public function save(User $user, array $data): LearnerProfile
    {
        return DB::transaction(function () use ($user, $data): LearnerProfile {
            $level = AcademicLevel::query()->with(['stage', 'curriculum'])->findOrFail((int) $data['academic_level_id']);

            $profile = LearnerProfile::query()->lockForUpdate()->firstOrNew(['user_id' => $user->id]);
            $birthMonth = CarbonImmutable::create((int) $data['birth_year'], (int) $data['birth_month'], 1);
            $this->guardBirthMonthChange($profile, $birthMonth);

            $profile->fill([
                'country_id' => (int) $data['country_id'],
                'curriculum_id' => $level->curriculum_id,
                'academic_level_id' => $level->id,
                'institution_id' => $level->isTertiary() ? ($data['institution_id'] ?? null) : null,
                'birth_month' => $birthMonth->toDateString(),
                'bilingual_ok' => isset($data['bilingual_ok']) && $data['bilingual_ok'] !== '' ? $data['bilingual_ok'] === '1' : null,
            ]);
            $profile->onboarded_at ??= now();
            $profile->user()->associate($user);
            $profile->save();

            $subjectIds = array_map('intval', $data['subjects'] ?? []);
            $offerings = SubjectOffering::query()->where('academic_level_id', $level->id)->whereIn('subject_id', $subjectIds)->pluck('id', 'subject_id');
            $profile->subjects()->sync(collect($subjectIds)->mapWithKeys(fn (int $id) => [$id => ['curriculum_subject_level_id' => $offerings[$id] ?? null]])->all());

            $teaching = array_values(array_unique($data['teaching'] ?? []));
            $this->languages->sync($user, LanguageRole::PrefersTeaching, $teaching);
            $this->languages->sync($user, LanguageRole::Understands, array_values(array_diff(array_unique($data['understands'] ?? []), $teaching)));
            $this->languages->sync($user, LanguageRole::Learning, array_values(array_unique($data['learning'] ?? [])));

            return $profile->refresh();
        });
    }

    /**
     * A learner may correct their birth month, but not into an older age band than the one already declared
     * (R-47 security S-04): otherwise a ten-year-old types 1990 and every safeguarding restriction lifts. Moving
     * to a younger band stays open (a typo that made a child an adult). An older band needs a guardian or an
     * operator, whose date of birth (safety's age records) outranks this one anyway.
     */
    private function guardBirthMonthChange(LearnerProfile $profile, CarbonImmutable $birthMonth): void
    {
        $current = $profile->exists ? $profile->ageBandBirthDate() : null;

        if ($current === null || $current->isSameMonth($birthMonth)) {
            return;
        }

        $rank = ['child' => 0, 'teen' => 1, 'adult' => 2];
        $before = $this->ageBands->bandFor($current);
        $after = $this->ageBands->bandFor($birthMonth->endOfMonth()->startOfDay());

        if ($before !== null && $after !== null && ($rank[$after] ?? 0) > ($rank[$before] ?? 0)) {
            throw ValidationException::withMessages([
                'birth_year' => 'Your month and year of birth can only be moved to an older age group by a parent or guardian, or by support.',
            ]);
        }
    }

    /**
     * The date of birth age banding should use for a user: the LAST day of the
     * stored birth month (never older than they are), or null without a profile.
     * This is what the safety seam BirthDateSource reads once the integration
     * provider binds it.
     */
    public function birthDateFor(User|int $user): ?CarbonImmutable
    {
        return LearnerProfile::query()->where('user_id', $user instanceof User ? $user->id : $user)->first()?->ageBandBirthDate();
    }

    /** The safety domain's age band for this learner (null = unknown; treat as most protected). */
    public function ageBand(LearnerProfile $profile): ?string
    {
        return $this->ageBands->bandFor($profile->ageBandBirthDate());
    }
}
