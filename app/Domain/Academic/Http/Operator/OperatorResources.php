<?php

namespace App\Domain\Academic\Http\Operator;

use App\Domain\Academic\Enums\CurriculumKind;
use App\Domain\Academic\Enums\InstitutionKind;
use App\Domain\Academic\Models\AcademicLevel;
use App\Domain\Academic\Models\AcademicStage;
use App\Domain\Academic\Models\Country;
use App\Domain\Academic\Models\Curriculum;
use App\Domain\Academic\Models\Institution;
use App\Domain\Academic\Models\Language;
use App\Domain\Academic\Models\QualificationFramework;
use App\Domain\Academic\Models\Subject;
use App\Domain\Academic\Models\SubjectOffering;
use App\Domain\Academic\Models\Topic;
use App\Models\Subject as LegacySubject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * Field definitions for the operator screens of the academic structure. One
 * generic controller and one view render every resource from these, so each
 * resource is a list with filters, an add form and an edit form per row.
 * Nothing is deleted: rows with an `is_active` flag are deactivated.
 *
 * Field types: text, number, textarea, list (comma-separated -> JSON array),
 * select (options closure; `nullable` adds an empty choice), bool.
 */
final class OperatorResources
{
    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return [
            'countries' => [
                'title' => 'Countries',
                'model' => Country::class,
                'order' => ['sort', 'name'],
                'columns' => ['code', 'name', 'sort'],
                'fields' => [
                    'code' => ['label' => 'ISO code (2 letters)', 'type' => 'text', 'rules' => fn (?Model $m) => ['required', 'string', 'size:2', 'alpha', Rule::unique('countries', 'code')->ignore($m?->getKey())], 'transform' => 'upper'],
                    'name' => ['label' => 'Name', 'type' => 'text', 'rules' => fn () => ['required', 'string', 'max:120']],
                    'sort' => ['label' => 'Sort order', 'type' => 'number', 'rules' => fn () => ['required', 'integer', 'min:0', 'max:65535']],
                ],
            ],
            'frameworks' => [
                'title' => 'Qualification frameworks',
                'model' => QualificationFramework::class,
                'with' => ['country'],
                'order' => ['code'],
                'columns' => ['code', 'name', 'short_name', 'country.name'],
                'fields' => [
                    'code' => ['label' => 'Code', 'type' => 'text', 'rules' => fn (?Model $m) => ['required', 'string', 'max:40', 'alpha_dash', Rule::unique('qualification_frameworks', 'code')->ignore($m?->getKey())]],
                    'name' => ['label' => 'Name', 'type' => 'text', 'rules' => fn () => ['required', 'string', 'max:160']],
                    'short_name' => ['label' => 'Short name', 'type' => 'text', 'rules' => fn () => ['nullable', 'string', 'max:40']],
                    'country_id' => ['label' => 'Country', 'type' => 'select', 'nullable' => 'International', 'options' => fn () => self::countries(), 'rules' => fn () => ['nullable', 'integer', 'exists:countries,id']],
                    'authority' => ['label' => 'Authority', 'type' => 'text', 'rules' => fn () => ['nullable', 'string', 'max:160']],
                    'description' => ['label' => 'Description', 'type' => 'textarea', 'rules' => fn () => ['nullable', 'string', 'max:2000']],
                ],
            ],
            'curricula' => [
                'title' => 'Curricula',
                'model' => Curriculum::class,
                'with' => ['country', 'framework'],
                'order' => ['sort', 'name'],
                'columns' => ['code', 'short_name', 'name', 'kind', 'country.name', 'framework.short_name'],
                'fields' => [
                    'code' => ['label' => 'Code', 'type' => 'text', 'rules' => fn (?Model $m) => ['required', 'string', 'max:40', 'alpha_dash', Rule::unique('curricula', 'code')->ignore($m?->getKey())]],
                    'name' => ['label' => 'Name', 'type' => 'text', 'rules' => fn () => ['required', 'string', 'max:160']],
                    'short_name' => ['label' => 'Short name', 'type' => 'text', 'rules' => fn () => ['nullable', 'string', 'max:40']],
                    'kind' => ['label' => 'Kind', 'type' => 'select', 'options' => fn () => array_combine(array_column(CurriculumKind::cases(), 'value'), array_column(CurriculumKind::cases(), 'value')), 'rules' => fn () => ['required', Rule::enum(CurriculumKind::class)]],
                    'country_id' => ['label' => 'Country', 'type' => 'select', 'nullable' => 'International (every country)', 'options' => fn () => self::countries(), 'rules' => fn () => ['nullable', 'integer', 'exists:countries,id']],
                    'qualification_framework_id' => ['label' => 'Qualification framework', 'type' => 'select', 'options' => fn () => QualificationFramework::query()->orderBy('code')->pluck('name', 'id')->all(), 'rules' => fn () => ['required', 'integer', 'exists:qualification_frameworks,id']],
                    'authority' => ['label' => 'Authority', 'type' => 'text', 'rules' => fn () => ['nullable', 'string', 'max:160']],
                    'description' => ['label' => 'Description', 'type' => 'textarea', 'rules' => fn () => ['nullable', 'string', 'max:2000']],
                    'sort' => ['label' => 'Sort order', 'type' => 'number', 'rules' => fn () => ['required', 'integer', 'min:0', 'max:65535']],
                ],
            ],
            'levels' => [
                'title' => 'Levels and grades',
                'model' => AcademicLevel::class,
                'with' => ['curriculum', 'stage'],
                'order' => ['curriculum_id', 'position'],
                'filters' => ['curriculum_id' => ['label' => 'Curriculum', 'options' => fn () => self::curricula()]],
                'columns' => ['curriculum.short_name', 'position', 'code', 'name', 'stage.name', 'nqf_level'],
                'fields' => [
                    'curriculum_id' => ['label' => 'Curriculum', 'type' => 'select', 'options' => fn () => self::curricula(), 'rules' => fn () => ['required', 'integer', 'exists:curricula,id']],
                    'academic_stage_id' => ['label' => 'Stage', 'type' => 'select', 'options' => fn () => AcademicStage::query()->orderBy('sort')->pluck('name', 'id')->all(), 'rules' => fn () => ['required', 'integer', 'exists:academic_stages,id']],
                    'code' => ['label' => 'Code (unique in the curriculum)', 'type' => 'text', 'rules' => fn (?Model $m) => ['required', 'string', 'max:40', 'alpha_dash',
                        Rule::unique('academic_levels', 'code')->where('curriculum_id', request()->integer('curriculum_id'))->ignore($m?->getKey())]],
                    'name' => ['label' => 'Name', 'type' => 'text', 'rules' => fn () => ['required', 'string', 'max:120']],
                    'short_name' => ['label' => 'Short name', 'type' => 'text', 'rules' => fn () => ['nullable', 'string', 'max:40']],
                    'position' => ['label' => 'Position (orders levels)', 'type' => 'number', 'rules' => fn () => ['required', 'integer', 'min:0', 'max:65535']],
                    'nqf_level' => ['label' => 'NQF level', 'type' => 'number', 'rules' => fn () => ['nullable', 'integer', 'min:1', 'max:10']],
                    'aliases' => ['label' => 'Aliases for "describe what you need" (comma-separated)', 'type' => 'list', 'rules' => fn () => ['nullable', 'string', 'max:1000']],
                ],
            ],
            'subjects' => [
                'title' => 'Subject catalogue',
                'model' => Subject::class,
                'with' => ['canonical', 'language'],
                'order' => ['name'],
                'filters' => ['category' => ['label' => 'Category', 'options' => fn () => Subject::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category', 'category')->all()]],
                'columns' => ['name', 'slug', 'category', 'level', 'canonical.name', 'language.name_en'],
                'fields' => [
                    'name' => ['label' => 'Name', 'type' => 'text', 'rules' => fn () => ['required', 'string', 'max:120']],
                    'slug' => ['label' => 'Slug', 'type' => 'text', 'rules' => fn (?Model $m) => ['nullable', 'string', 'max:80', 'alpha_dash', Rule::unique('subjects', 'slug')->ignore($m?->getKey())]],
                    'level' => ['label' => 'Legacy band (shown on the old screens)', 'type' => 'select', 'options' => fn () => LegacySubject::LEVELS, 'rules' => fn () => ['required', Rule::in(array_keys(LegacySubject::LEVELS))]],
                    'family' => ['label' => 'Family', 'type' => 'text', 'rules' => fn () => ['nullable', 'string', 'max:60', 'alpha_dash']],
                    'category' => ['label' => 'Category', 'type' => 'text', 'rules' => fn () => ['nullable', 'string', 'max:40', 'alpha_dash']],
                    'language_id' => ['label' => 'Language taught (language subjects)', 'type' => 'select', 'nullable' => 'Not a language subject', 'options' => fn () => self::languages(), 'rules' => fn () => ['nullable', 'integer', 'exists:languages,id']],
                    'language_tier' => ['label' => 'Language tier', 'type' => 'select', 'nullable' => 'None', 'options' => fn () => ['home' => 'Home Language', 'first_additional' => 'First Additional Language', 'second_additional' => 'Second Additional Language'], 'rules' => fn () => ['nullable', Rule::in(['home', 'first_additional', 'second_additional'])]],
                    'canonical_subject_id' => ['label' => 'Same subject as (merges a duplicate; bookings keep their row)', 'type' => 'select', 'nullable' => 'This is a catalogue subject', 'options' => fn () => Subject::query()->canonical()->orderBy('name')->pluck('name', 'id')->all(),
                        'rules' => fn (?Model $m) => ['nullable', 'integer', Rule::exists('subjects', 'id')->whereNull('canonical_subject_id'), Rule::notIn([$m?->getKey()])]],
                    'aliases' => ['label' => 'Aliases (comma-separated)', 'type' => 'list', 'rules' => fn () => ['nullable', 'string', 'max:1000']],
                    'description' => ['label' => 'Description', 'type' => 'textarea', 'rules' => fn () => ['nullable', 'string', 'max:2000']],
                ],
            ],
            'offerings' => [
                'title' => 'Subject offerings (subject at a level)',
                'model' => SubjectOffering::class,
                'with' => ['level.curriculum', 'subject'],
                'order' => ['academic_level_id', 'subject_id'],
                'filters' => [
                    'curriculum_id' => ['label' => 'Curriculum', 'options' => fn () => self::curricula()],
                    'academic_level_id' => ['label' => 'Level', 'options' => fn () => self::levels()],
                    'subject_id' => ['label' => 'Subject', 'options' => fn () => Subject::query()->canonical()->orderBy('name')->pluck('name', 'id')->all()],
                ],
                'columns' => ['level.curriculum.short_name', 'level.name', 'subject.name', 'is_compulsory'],
                'fields' => [
                    'academic_level_id' => ['label' => 'Level', 'type' => 'select', 'options' => fn () => self::levels(), 'rules' => fn () => ['required', 'integer', 'exists:academic_levels,id']],
                    'subject_id' => ['label' => 'Subject', 'type' => 'select', 'options' => fn () => Subject::query()->canonical()->orderBy('name')->pluck('name', 'id')->all(),
                        'rules' => fn (?Model $m) => ['required', 'integer', Rule::exists('subjects', 'id')->whereNull('canonical_subject_id'),
                            Rule::unique('curriculum_subject_levels', 'subject_id')->where('academic_level_id', request()->integer('academic_level_id'))->ignore($m?->getKey())]],
                    'is_compulsory' => ['label' => 'Compulsory at this level', 'type' => 'bool', 'rules' => fn () => ['boolean']],
                ],
            ],
            'topics' => [
                'title' => 'Topics',
                'model' => Topic::class,
                'with' => ['offering.level.curriculum', 'offering.subject', 'parent'],
                'order' => ['curriculum_subject_level_id', 'position'],
                'filters' => ['curriculum_subject_level_id' => ['label' => 'Offering', 'options' => fn () => self::offeringsWithTopicsOrAll()]],
                'columns' => ['offering.level.curriculum.short_name', 'offering.level.name', 'offering.subject.name', 'parent.name', 'position', 'name'],
                'fields' => [
                    'curriculum_subject_level_id' => ['label' => 'Offering', 'type' => 'select', 'options' => fn () => self::offeringsWithTopicsOrAll(), 'rules' => fn () => ['required', 'integer', 'exists:curriculum_subject_levels,id']],
                    'parent_id' => ['label' => 'Parent topic', 'type' => 'select', 'nullable' => 'None (top level)', 'options' => fn () => Topic::query()->whereNull('parent_id')
                        ->when(request()->integer('curriculum_subject_level_id'), fn (Builder $q, int $id) => $q->where('curriculum_subject_level_id', $id))
                        ->orderBy('name')->limit(500)->pluck('name', 'id')->all(),
                        'rules' => fn (?Model $m) => ['nullable', 'integer', Rule::exists('academic_topics', 'id')->where('curriculum_subject_level_id', request()->integer('curriculum_subject_level_id')), Rule::notIn([$m?->getKey()])]],
                    'name' => ['label' => 'Name', 'type' => 'text', 'rules' => fn () => ['required', 'string', 'max:160']],
                    'slug' => ['label' => 'Slug (leave blank to derive from the name)', 'type' => 'text', 'rules' => fn (?Model $m) => ['nullable', 'string', 'max:160', 'alpha_dash',
                        Rule::unique('academic_topics', 'slug')->where('curriculum_subject_level_id', request()->integer('curriculum_subject_level_id'))->ignore($m?->getKey())]],
                    'position' => ['label' => 'Position', 'type' => 'number', 'rules' => fn () => ['required', 'integer', 'min:0', 'max:65535']],
                    'description' => ['label' => 'Description', 'type' => 'textarea', 'rules' => fn () => ['nullable', 'string', 'max:2000']],
                ],
            ],
            'institutions' => [
                'title' => 'Institutions',
                'model' => Institution::class,
                'with' => ['country'],
                'order' => ['name'],
                'filters' => ['country_id' => ['label' => 'Country', 'options' => fn () => self::countries()]],
                'columns' => ['name', 'short_name', 'kind', 'country.name', 'is_public'],
                'fields' => [
                    'country_id' => ['label' => 'Country', 'type' => 'select', 'options' => fn () => self::countries(), 'rules' => fn () => ['required', 'integer', 'exists:countries,id']],
                    'name' => ['label' => 'Name', 'type' => 'text', 'rules' => fn (?Model $m) => ['required', 'string', 'max:200', Rule::unique('institutions', 'name')->where('country_id', request()->integer('country_id'))->ignore($m?->getKey())]],
                    'short_name' => ['label' => 'Short name', 'type' => 'text', 'rules' => fn () => ['nullable', 'string', 'max:40']],
                    'kind' => ['label' => 'Kind', 'type' => 'select', 'options' => fn () => array_combine(array_column(InstitutionKind::cases(), 'value'), array_column(InstitutionKind::cases(), 'value')), 'rules' => fn () => ['required', Rule::enum(InstitutionKind::class)]],
                    'is_public' => ['label' => 'Public institution', 'type' => 'bool', 'rules' => fn () => ['boolean']],
                ],
            ],
            'languages' => [
                'title' => 'Languages',
                'model' => Language::class,
                'order' => ['sort', 'name_en'],
                'columns' => ['code', 'name_en', 'name_native', 'script', 'modality', 'is_official_za'],
                'fields' => [
                    'code' => ['label' => 'BCP 47 code', 'type' => 'text', 'rules' => fn (?Model $m) => ['required', 'string', 'max:16', 'regex:/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', Rule::unique('languages', 'code')->ignore($m?->getKey())]],
                    'name_en' => ['label' => 'English name', 'type' => 'text', 'rules' => fn () => ['required', 'string', 'max:80']],
                    'name_native' => ['label' => 'Own-language name', 'type' => 'text', 'rules' => fn () => ['required', 'string', 'max:80']],
                    'script' => ['label' => 'Script (ISO 15924, e.g. Latn; blank for a signed language)', 'type' => 'text', 'rules' => fn () => ['nullable', 'string', 'size:4', 'alpha']],
                    'modality' => ['label' => 'Modality', 'type' => 'select', 'options' => fn () => ['written' => 'written', 'signed' => 'signed'], 'rules' => fn () => ['required', Rule::in(['written', 'signed'])]],
                    'is_official_za' => ['label' => 'Official language of South Africa', 'type' => 'bool', 'rules' => fn () => ['boolean']],
                    'aliases' => ['label' => 'Aliases (comma-separated)', 'type' => 'list', 'rules' => fn () => ['nullable', 'string', 'max:1000']],
                    'sort' => ['label' => 'Sort order', 'type' => 'number', 'rules' => fn () => ['required', 'integer', 'min:0', 'max:65535']],
                ],
            ],
        ];
    }

    /** @return array<int, string> */
    private static function countries(): array
    {
        return Country::query()->orderBy('sort')->orderBy('name')->pluck('name', 'id')->all();
    }

    /** @return array<int, string> */
    private static function curricula(): array
    {
        return Curriculum::query()->orderBy('sort')->get()->mapWithKeys(fn (Curriculum $c) => [$c->id => $c->label()])->all();
    }

    /** @return array<int, string> */
    private static function languages(): array
    {
        return Language::query()->orderBy('sort')->pluck('name_en', 'id')->all();
    }

    /** @return array<int, string> */
    private static function levels(): array
    {
        return AcademicLevel::query()->with('curriculum')
            ->when(request()->integer('curriculum_id'), fn (Builder $q, int $id) => $q->where('curriculum_id', $id))
            ->orderBy('curriculum_id')->orderBy('position')->get()
            ->mapWithKeys(fn (AcademicLevel $l) => [$l->id => $l->curriculum->label().' · '.$l->name])->all();
    }

    /**
     * Offerings for the topic screens: those of the filtered level when one is
     * chosen, otherwise only offerings that already have topics (the full list
     * is ~900 rows).
     *
     * @return array<int, string>
     */
    private static function offeringsWithTopicsOrAll(): array
    {
        $query = SubjectOffering::query()->with(['level.curriculum', 'subject']);
        $query = request()->integer('academic_level_id')
            ? $query->where('academic_level_id', request()->integer('academic_level_id'))
            : $query->whereIn('id', Topic::query()->select('curriculum_subject_level_id'))
                ->when(request()->integer('curriculum_subject_level_id'), fn (Builder $q, int $id) => $q->orWhere('id', $id));

        return $query->orderBy('academic_level_id')->get()
            ->mapWithKeys(fn (SubjectOffering $o) => [$o->id => $o->level->curriculum->label().' · '.$o->level->name.' · '.$o->subject->name])->all();
    }
}
