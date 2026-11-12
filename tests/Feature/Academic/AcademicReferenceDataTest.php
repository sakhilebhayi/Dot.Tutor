<?php

namespace Tests\Feature\Academic;

use App\Domain\Academic\Models\AcademicLevel;
use App\Domain\Academic\Models\Curriculum;
use App\Domain\Academic\Models\Institution;
use App\Domain\Academic\Models\Language;
use App\Domain\Academic\Models\SubjectOffering;
use App\Domain\Academic\Models\Topic;
use App\Domain\Academic\Services\ReferenceDataSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsAcademicStructure;
use Tests\TestCase;

class AcademicReferenceDataTest extends TestCase
{
    use RefreshDatabase;
    use SeedsAcademicStructure;

    public function test_languages_are_the_eleven_official_written_languages_sasl_and_portuguese_french_swahili(): void
    {
        $this->seedAcademic();

        $official = Language::query()->where('is_official_za', true)->where('modality', 'written')->orderBy('sort')->pluck('code')->all();
        $this->assertSame(['en', 'zu', 'xh', 'af', 'nso', 'tn', 'st', 'ts', 'ss', 've', 'nr'], $official);

        $this->assertSame('signed', Language::query()->where('code', 'sfs')->value('modality'));
        $this->assertNull(Language::query()->where('code', 'sfs')->value('script'));
        foreach (['pt' => 'Português', 'fr' => 'Français', 'sw' => 'Kiswahili'] as $code => $native) {
            $language = Language::query()->where('code', $code)->firstOrFail();
            $this->assertFalse($language->is_official_za);
            $this->assertSame($native, $language->name_native);
            $this->assertSame('Latn', $language->script);
        }
        $this->assertSame('Sesotho sa Leboa', Language::query()->where('code', 'nso')->value('name_native'));
    }

    public function test_caps_and_ieb_have_grades_one_to_twelve_in_order_grouped_into_phases(): void
    {
        $this->seedAcademic();

        foreach (['za-caps', 'za-ieb'] as $code) {
            $levels = Curriculum::query()->where('code', $code)->firstOrFail()->levels()->with('stage')->get();
            $this->assertSame(array_map(fn ($n) => "Grade {$n}", range(1, 12)), $levels->pluck('name')->all());
            $this->assertSame('foundation', $levels[0]->stage->code);
            $this->assertSame('intermediate', $levels[3]->stage->code);
            $this->assertSame('senior', $levels[6]->stage->code);
            $this->assertSame('fet', $levels[11]->stage->code);
            $this->assertSame(4, $levels[11]->nqf_level);
        }

        $caps = Curriculum::query()->where('code', 'za-caps')->with('country', 'framework')->firstOrFail();
        $this->assertSame('ZA', $caps->country->code);
        $this->assertSame('za-gfetqsf', $caps->framework->code);
    }

    public function test_tertiary_track_runs_from_first_year_to_doctoral_and_professional(): void
    {
        $this->seedAcademic();

        $levels = Curriculum::query()->where('code', 'za-higher-education')->firstOrFail()->levels()->with('stage')->get();
        $this->assertSame(
            ['undergraduate-1', 'undergraduate-2', 'undergraduate-3', 'undergraduate-4', 'honours', 'pg-diploma', 'masters', 'doctoral', 'professional'],
            $levels->pluck('code')->all(),
        );
        $this->assertSame(['undergraduate', 'honours', 'pg_diploma', 'masters', 'doctoral', 'professional'], $levels->pluck('stage.code')->unique()->values()->all());
        $this->assertSame(10, $levels->firstWhere('code', 'doctoral')->nqf_level);
    }

    public function test_cambridge_is_an_international_curriculum_with_its_own_stages(): void
    {
        $this->seedAcademic();

        $cambridge = Curriculum::query()->where('code', 'cambridge')->firstOrFail();
        $this->assertTrue($cambridge->isInternational());
        $this->assertSame(['Cambridge Primary Stage 1', 'Cambridge IGCSE', 'Cambridge A Level'], [
            $cambridge->levels()->first()->name,
            $cambridge->levels()->where('code', 'igcse')->value('name'),
            $cambridge->levels()->get()->last()->name,
        ]);

        // International curricula are offered in every country.
        $this->assertTrue(Curriculum::query()->availableIn(999)->where('code', 'cambridge')->exists());
        $this->assertFalse(Curriculum::query()->availableIn(999)->where('code', 'za-caps')->exists());
    }

    public function test_offerings_follow_the_caps_phases(): void
    {
        $this->seedAcademic();

        $grade10 = $this->level('za-caps', 'grade-10');
        foreach (['mathematics', 'mathematical-literacy', 'physical-sciences', 'life-sciences', 'accounting', 'geography', 'history',
            'en-home-language', 'en-first-additional-language', 'af-home-language', 'af-first-additional-language',
            'zu-home-language', 'zu-first-additional-language', 'life-orientation'] as $slug) {
            $this->assertTrue(
                SubjectOffering::query()->where('academic_level_id', $grade10->id)->where('subject_id', $this->subject($slug)->id)->exists(),
                "{$slug} should be offered at CAPS Grade 10",
            );
        }

        $grade3 = $this->level('za-caps', 'grade-3');
        $this->assertTrue(SubjectOffering::query()->where('academic_level_id', $grade3->id)->where('subject_id', $this->subject('life-skills')->id)->exists());
        $this->assertFalse(SubjectOffering::query()->where('academic_level_id', $grade3->id)->where('subject_id', $this->subject('accounting')->id)->exists());
        $this->assertFalse(SubjectOffering::query()->where('academic_level_id', $grade10->id)->where('subject_id', $this->subject('life-skills')->id)->exists());

        // IEB adds Advanced Programme Mathematics at FET only.
        $ap = $this->subject('advanced-programme-mathematics');
        $this->assertTrue(SubjectOffering::query()->where('academic_level_id', $this->level('za-ieb', 'grade-12')->id)->where('subject_id', $ap->id)->exists());
        $this->assertFalse(SubjectOffering::query()->where('academic_level_id', $this->level('za-caps', 'grade-12')->id)->where('subject_id', $ap->id)->exists());

        // The stored curriculum always matches the level's curriculum.
        $this->assertSame(0, SubjectOffering::query()->join('academic_levels', 'academic_levels.id', '=', 'curriculum_subject_levels.academic_level_id')
            ->whereColumn('academic_levels.curriculum_id', '!=', 'curriculum_subject_levels.curriculum_id')->count());
    }

    public function test_starter_topics_are_seeded_for_core_offerings_only(): void
    {
        $this->seedAcademic();

        $offering = SubjectOffering::query()->where('academic_level_id', $this->level('za-caps', 'grade-12')->id)
            ->where('subject_id', $this->subject('mathematics')->id)->firstOrFail();
        $this->assertContains('Differential calculus', $offering->topics->pluck('name'));

        $physics = SubjectOffering::query()->where('academic_level_id', $this->level('za-caps', 'grade-10')->id)
            ->where('subject_id', $this->subject('physical-sciences')->id)->firstOrFail();
        $mechanics = $physics->topics()->whereNull('parent_id')->where('name', 'Mechanics')->firstOrFail();
        $this->assertSame(['Vectors and scalars', 'Motion in one dimension', 'Energy'], $mechanics->children->pluck('name')->all());

        $geography = SubjectOffering::query()->where('academic_level_id', $this->level('za-caps', 'grade-10')->id)
            ->where('subject_id', $this->subject('geography')->id)->firstOrFail();
        $this->assertSame(0, $geography->topics()->count());
    }

    public function test_the_26_public_universities_are_seeded_and_nothing_else(): void
    {
        $this->seedAcademic();

        $this->assertSame(26, Institution::query()->count());
        $this->assertTrue(Institution::query()->where('name', 'University of the Witwatersrand')->where('short_name', 'Wits')->exists());
        $this->assertSame(0, Institution::query()->where('kind', 'school')->count());
    }

    public function test_sync_is_idempotent_and_never_overwrites_an_operator_edit(): void
    {
        $this->seedAcademic();
        $counts = [AcademicLevel::query()->count(), SubjectOffering::query()->count(), Topic::query()->count(), Language::query()->count()];

        $grade = $this->level('za-caps', 'grade-7');
        $grade->update(['name' => 'Graad 7', 'is_active' => false]);
        Language::query()->where('code', 'zu')->update(['name_en' => 'Zulu']);

        $report = app(ReferenceDataSync::class)->run();

        $this->assertSame([], $report['created']);
        $this->assertSame($counts, [AcademicLevel::query()->count(), SubjectOffering::query()->count(), Topic::query()->count(), Language::query()->count()]);
        $this->assertSame('Graad 7', $grade->fresh()->name);
        $this->assertFalse($grade->fresh()->is_active);
        $this->assertSame('Zulu', Language::query()->where('code', 'zu')->value('name_en'));
    }

    public function test_the_sync_command_reports_what_it_did(): void
    {
        $this->artisan('academic:sync')->expectsOutputToContain('offerings')->assertSuccessful();
        $this->artisan('academic:sync')->expectsOutputToContain('nothing inserted')->assertSuccessful();
    }
}
