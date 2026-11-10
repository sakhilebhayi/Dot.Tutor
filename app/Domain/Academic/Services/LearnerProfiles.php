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

            $profile = LearnerProfile::query()->firstOrNew(['user_id' => $user->id]);
            $profile->fill([
                'country_id' => (int) $data['country_id'],
                'curriculum_id' => $level->curriculum_id,
                'academic_level_id' => $level->id,
                'institution_id' => $level->isTertiary() ? ($data['institution_id'] ?? null) : null,
                'birth_month' => CarbonImmutable::create((int) $data['birth_year'], (int) $data['birth_month'], 1)->toDateString(),
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

    /** The safety domain's age band for this learner (null = unknown; treat as most protected). */
    public function ageBand(LearnerProfile $profile): ?string
    {
        return $this->ageBands->bandFor($profile->ageBandBirthDate());
    }
}
