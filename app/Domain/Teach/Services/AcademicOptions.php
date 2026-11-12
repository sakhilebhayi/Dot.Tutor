<?php

namespace App\Domain\Teach\Services;

use App\Domain\Academic\Models\AcademicLevel;
use App\Domain\Academic\Models\Curriculum;
use App\Domain\Academic\Models\Language;
use App\Models\Subject;

/**
 * Choices for the workspace forms, read from the academic reference data (php artisan academic:sync). When that data
 * has not been synced, lists come back empty and the forms say so instead of inventing levels.
 */
class AcademicOptions
{
    /** @return list<array{value: int, label: string}> */
    public function subjects(): array
    {
        return Subject::query()->active()->whereNull('canonical_subject_id')->orderBy('name')->get(['id', 'name'])
            ->map(fn ($s) => ['value' => (int) $s->id, 'label' => $s->name])->all();
    }

    /**
     * Every active level, grouped by curriculum for a select with optgroups.
     *
     * @return list<array{value: int, label: string, group: string}>
     */
    public function levels(): array
    {
        $curricula = Curriculum::query()->active()->orderBy('sort')->orderBy('name')->get()->keyBy('id');

        return AcademicLevel::query()->active()->whereIn('curriculum_id', $curricula->keys())
            ->orderBy('curriculum_id')->orderBy('position')->get()
            ->sortBy(fn (AcademicLevel $l) => [$curricula[$l->curriculum_id]->sort ?? 0, $curricula[$l->curriculum_id]->name, $l->position])
            // The curriculum is in the label too: a closed select shows only the option, and "Grade 10" exists in CAPS and IEB.
            ->map(fn (AcademicLevel $l) => ['value' => (int) $l->id, 'label' => $curricula[$l->curriculum_id]->label().' '.$l->name, 'group' => $curricula[$l->curriculum_id]->label()])
            ->values()->all();
    }

    /** @return array<int, array{name: string, curriculum_id: int, curriculum: string, stage: ?string}> */
    public function levelIndex(): array
    {
        return AcademicLevel::query()->with(['curriculum:id,name,short_name', 'stage:id,code'])->get()
            ->mapWithKeys(fn (AcademicLevel $l) => [(int) $l->id => [
                'name' => $l->name,
                'curriculum_id' => (int) $l->curriculum_id,
                'curriculum' => $l->curriculum?->label() ?? '',
                'stage' => $l->stage?->code,
            ]])->all();
    }

    /** @return list<array{code: string, name: string, endonym: string}> */
    public function languages(): array
    {
        return Language::query()->active()->orderBy('sort')->orderBy('name_en')->get()
            ->map(fn (Language $l) => ['code' => $l->code, 'name' => $l->name_en, 'endonym' => $l->name_native ?: $l->name_en])->all();
    }

    /** Map a seeded stage code to the design system's binding colour key (honours, pg_diploma, masters share one). */
    public static function stageKey(?string $code): ?string
    {
        return match ($code) {
            null => null,
            'honours', 'pg_diploma', 'masters' => 'postgraduate',
            'foundation', 'intermediate', 'senior', 'fet', 'undergraduate', 'doctoral', 'professional' => $code,
            default => null,
        };
    }
}
