<?php

namespace App\Domain\Academic\Services;

use App\Domain\Academic\Models\AcademicLevel;
use App\Domain\Academic\Models\AcademicStage;
use App\Domain\Academic\Models\Country;
use App\Domain\Academic\Models\Curriculum;
use App\Domain\Academic\Models\Institution;
use App\Domain\Academic\Models\Language;
use App\Domain\Academic\Models\LegacyLevelMapping;
use App\Domain\Academic\Models\QualificationFramework;
use App\Domain\Academic\Models\Subject;
use App\Domain\Academic\Models\SubjectOffering;
use App\Domain\Academic\Models\Topic;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Inserts the reference academic structure and languages, then maps the legacy
 * subject rows onto the catalogue. Idempotent and edit-safe: everything is
 * matched on a stable code (slug, BCP 47 code, ISO code, curriculum + level
 * code) and only MISSING rows are inserted; an existing row an operator has
 * renamed, re-aliased or deactivated is never touched. Nothing is deleted.
 *
 * Run by `php artisan academic:sync` (deploy step) and by AcademicReferenceSeeder.
 */
class ReferenceDataSync
{
    /** @var array<string, int> */
    private array $created = [];

    public function __construct(private readonly LegacySubjectMapper $legacy) {}

    /**
     * @return array{created: array<string, int>, legacy: array{linked: int, catalogued: int, unmapped_levels: list<string>, rows_before: int, rows_after: int}}
     */
    public function run(): array
    {
        $this->created = [];
        $structure = require app_path('Domain/Academic/Data/structure.php');
        $catalogue = require app_path('Domain/Academic/Data/subjects.php');

        DB::transaction(function () use ($structure, $catalogue): void {
            $this->languages(require app_path('Domain/Academic/Data/languages.php'));
            $countries = $this->countries($structure['countries']);
            $frameworks = $this->frameworks($structure['frameworks'], $countries);
            $stages = $this->stages($structure['stages']);
            $levels = $this->curricula($structure['curricula'], $countries, $frameworks, $stages);
            $newSubjectIds = $this->subjects($catalogue['subjects']);
            $this->offerings($catalogue['offerings'], $levels);
            $this->topics(require app_path('Domain/Academic/Data/topics.php'), $levels);
            $this->institutions(require app_path('Domain/Academic/Data/institutions.php'), $countries['ZA']);
            $this->legacyLevels($structure['legacy_levels'], $levels);
            $this->legacy->assignBands($newSubjectIds);
        });

        return ['created' => $this->created, 'legacy' => $this->legacy->run()];
    }

    private function bump(string $kind, int $by = 1): void
    {
        $this->created[$kind] = ($this->created[$kind] ?? 0) + $by;
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function languages(array $rows): void
    {
        foreach ($rows as $row) {
            $language = Language::query()->firstOrCreate(['code' => $row['code']], [
                'name_en' => $row['name_en'],
                'name_native' => $row['name_native'],
                'script' => $row['script'],
                'modality' => $row['modality'],
                'is_official_za' => $row['is_official_za'],
                'aliases' => array_values(array_unique(array_merge($row['aliases'], $row['teaching_phrases'] ?? []))),
                'sort' => $row['sort'],
                'is_active' => true,
            ]);
            $language->wasRecentlyCreated && $this->bump('languages');
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int> ISO code => id
     */
    private function countries(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $country = Country::query()->firstOrCreate(['code' => $row['code']], ['name' => $row['name'], 'sort' => $row['sort']]);
            $country->wasRecentlyCreated && $this->bump('countries');
            $ids[$row['code']] = $country->id;
        }

        return $ids;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, int>  $countries
     * @return array<string, int>
     */
    private function frameworks(array $rows, array $countries): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $framework = QualificationFramework::query()->firstOrCreate(['code' => $row['code']], [
                'country_id' => $row['country'] ? $countries[$row['country']] : null,
                'name' => $row['name'],
                'short_name' => $row['short_name'],
                'authority' => $row['authority'],
                'description' => $row['description'],
            ]);
            $framework->wasRecentlyCreated && $this->bump('frameworks');
            $ids[$row['code']] = $framework->id;
        }

        return $ids;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function stages(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $stage = AcademicStage::query()->firstOrCreate(['code' => $row['code']], ['name' => $row['name'], 'kind' => $row['kind'], 'sort' => $row['sort']]);
            $stage->wasRecentlyCreated && $this->bump('stages');
            $ids[$row['code']] = $stage->id;
        }

        return $ids;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, int>  $countries
     * @param  array<string, int>  $frameworks
     * @param  array<string, int>  $stages
     * @return array<string, array<string, int>> curriculum code => level code => level id
     */
    private function curricula(array $rows, array $countries, array $frameworks, array $stages): array
    {
        $levels = [];
        foreach ($rows as $row) {
            $curriculum = Curriculum::query()->firstOrCreate(['code' => $row['code']], [
                'country_id' => $row['country'] ? $countries[$row['country']] : null,
                'qualification_framework_id' => $frameworks[$row['framework']],
                'name' => $row['name'],
                'short_name' => $row['short_name'],
                'kind' => $row['kind'],
                'authority' => $row['authority'],
                'description' => $row['description'],
                'sort' => $row['sort'],
            ]);
            $curriculum->wasRecentlyCreated && $this->bump('curricula');

            foreach ($row['levels'] as $index => $level) {
                $model = AcademicLevel::query()->firstOrCreate(
                    ['curriculum_id' => $curriculum->id, 'code' => $level['code']],
                    [
                        'academic_stage_id' => $stages[$level['stage']],
                        'name' => $level['name'],
                        'short_name' => $level['short_name'],
                        'position' => ($index + 1) * 10,
                        'nqf_level' => $level['nqf_level'],
                        'aliases' => $level['aliases'] ?: null,
                    ],
                );
                $model->wasRecentlyCreated && $this->bump('levels');
                $levels[$row['code']][$level['code']] = $model->id;
            }
        }

        return $levels;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<int> ids of subject rows created by this run
     */
    private function subjects(array $rows): array
    {
        $languageIds = Language::query()->pluck('id', 'code');
        $created = [];

        foreach ($rows as $row) {
            if (Subject::query()->where('slug', $row['slug'])->exists()) {
                continue;
            }

            $attributes = [
                'slug' => $row['slug'],
                'family' => $row['family'],
                'category' => $row['category'],
                'aliases' => $row['aliases'],
                'language_id' => isset($row['language']) ? ($languageIds[$row['language']] ?? null) : null,
                'language_tier' => $row['tier'] ?? null,
            ];

            // Adopt the oldest legacy row with the same name: its id (and every booking that points at it) is kept.
            $legacy = Subject::query()
                ->whereNull('slug')
                ->whereNull('canonical_subject_id')
                ->whereRaw('lower(name) = ?', [mb_strtolower($row['name'])])
                ->orderBy('id')
                ->first();

            if ($legacy) {
                $legacy->forceFill(array_filter($attributes, fn ($value) => $value !== null))->save();
                $this->bump('subjects_adopted');

                continue;
            }

            $subject = Subject::query()->create($attributes + ['name' => $row['name'], 'is_active' => true]);
            $created[] = $subject->id;
            $this->bump('subjects');
        }

        return $created;
    }

    /**
     * @param  array<string, list<array{levels: list<string>, subjects: list<string>, compulsory?: list<string>}>>  $byCurriculum
     * @param  array<string, array<string, int>>  $levels
     */
    private function offerings(array $byCurriculum, array $levels): void
    {
        $subjectIds = Subject::query()->whereNotNull('slug')->pluck('id', 'slug');
        $existing = SubjectOffering::query()->get(['academic_level_id', 'subject_id'])
            ->mapWithKeys(fn ($o) => ["{$o->academic_level_id}:{$o->subject_id}" => true])->all();
        $now = Carbon::now();
        $insert = [];

        foreach ($byCurriculum as $curriculumCode => $groups) {
            $curriculumId = Curriculum::query()->where('code', $curriculumCode)->value('id');
            foreach ($groups as $group) {
                foreach ($group['levels'] as $levelCode) {
                    $levelId = $levels[$curriculumCode][$levelCode] ?? null;
                    if (! $levelId) {
                        continue;
                    }
                    foreach ($group['subjects'] as $slug) {
                        $subjectId = $subjectIds[$slug] ?? null;
                        if (! $subjectId || isset($existing["{$levelId}:{$subjectId}"])) {
                            continue;
                        }
                        $existing["{$levelId}:{$subjectId}"] = true;
                        $insert[] = [
                            'curriculum_id' => $curriculumId,
                            'academic_level_id' => $levelId,
                            'subject_id' => $subjectId,
                            'is_compulsory' => in_array($slug, $group['compulsory'] ?? [], true),
                            'is_active' => true,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }
            }
        }

        foreach (array_chunk($insert, 200) as $chunk) {
            SubjectOffering::query()->insert($chunk);
        }
        $insert !== [] && $this->bump('offerings', count($insert));
    }

    /**
     * @param  array<string, array<string, array<string, list<mixed>>>>  $byCurriculum
     * @param  array<string, array<string, int>>  $levels
     */
    private function topics(array $byCurriculum, array $levels): void
    {
        foreach ($byCurriculum as $curriculumCode => $bySubject) {
            foreach ($bySubject as $slug => $byLevel) {
                $subjectId = Subject::query()->where('slug', $slug)->value('id');
                foreach ($byLevel as $levelCode => $topics) {
                    $offeringId = SubjectOffering::query()
                        ->where('academic_level_id', $levels[$curriculumCode][$levelCode] ?? 0)
                        ->where('subject_id', $subjectId)
                        ->value('id');
                    if (! $offeringId) {
                        continue;
                    }
                    foreach ($topics as $position => $topic) {
                        [$name, $children] = is_array($topic) ? $topic : [$topic, []];
                        $parent = $this->topic($offeringId, $name, ($position + 1) * 10, null);
                        foreach ($children as $childPosition => $child) {
                            $this->topic($offeringId, $child, ($childPosition + 1) * 10, $parent->id);
                        }
                    }
                }
            }
        }
    }

    private function topic(int $offeringId, string $name, int $position, ?int $parentId): Topic
    {
        $topic = Topic::query()->firstOrCreate(
            ['curriculum_subject_level_id' => $offeringId, 'slug' => Str::slug($name)],
            ['name' => $name, 'position' => $position, 'parent_id' => $parentId],
        );
        $topic->wasRecentlyCreated && $this->bump('topics');

        return $topic;
    }

    /** @param  list<array{name: string, short_name: ?string, kind: string}>  $rows */
    private function institutions(array $rows, int $countryId): void
    {
        foreach ($rows as $row) {
            $institution = Institution::query()->firstOrCreate(
                ['country_id' => $countryId, 'name' => $row['name']],
                ['short_name' => $row['short_name'], 'kind' => $row['kind'], 'is_public' => true],
            );
            $institution->wasRecentlyCreated && $this->bump('institutions');
        }
    }

    /**
     * @param  array<string, array<string, list<string>>>  $map
     * @param  array<string, array<string, int>>  $levels
     */
    private function legacyLevels(array $map, array $levels): void
    {
        foreach ($map as $legacyLevel => $byCurriculum) {
            foreach ($byCurriculum as $curriculumCode => $levelCodes) {
                foreach ($levelCodes as $levelCode) {
                    $levelId = $levels[$curriculumCode][$levelCode] ?? null;
                    if ($levelId) {
                        $row = LegacyLevelMapping::query()->firstOrCreate(['legacy_level' => $legacyLevel, 'academic_level_id' => $levelId]);
                        $row->wasRecentlyCreated && $this->bump('legacy_level_mappings');
                    }
                }
            }
        }
    }
}
