<?php

namespace App\Domain\Teach\Services;

use App\Domain\Academic\Models\AcademicLevel;
use App\Domain\Academic\Models\Curriculum;
use App\Domain\Academic\Models\Language;
use App\Domain\Academic\Models\Subject as AcademicSubject;
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

    /** Subject areas, as the subject picker groups them (D-2205), in reading order. */
    public const AREAS = [
        'mathematics' => 'Mathematics',
        'sciences' => 'Sciences',
        'languages' => 'Languages',
        'humanities' => 'Humanities',
        'commerce' => 'Commerce and accounting',
        'technology' => 'Technology and computing',
        'arts' => 'Arts',
        'life-skills' => 'Life skills and orientation',
        'services' => 'Services, hospitality and tourism',
        'academic-skills' => 'Study and research skills',
    ];

    /**
     * Subjects for the searchable picker (D-2205), grouped by subject area. The stage is not a property of the
     * subject (the one Mathematics row serves Grade 1 to Grade 12; its legacy `level` column says "primary"), so it
     * is chosen in the level range beside the subject, never printed next to the name. Seeded aliases are extra
     * search words, so "maths", "wiskunde" or "isiZulu HL" find the subject.
     *
     * @return list<array{value: int, label: string, group: string, search: string}>
     */
    public function subjectChoices(): array
    {
        $order = array_flip(array_keys(self::AREAS));

        return AcademicSubject::query()->where('is_active', true)->whereNull('canonical_subject_id')->get()
            ->sortBy(fn (AcademicSubject $s) => [$order[$s->category] ?? 99, $s->name])
            ->map(fn (AcademicSubject $s) => [
                'value' => (int) $s->id,
                'label' => $s->name,
                'group' => self::AREAS[$s->category] ?? 'Other subjects',
                'search' => implode(' ', (array) ($s->aliases ?? [])),
            ])->values()->all();
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
