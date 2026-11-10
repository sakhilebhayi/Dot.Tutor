<?php

namespace Tests\Feature\Academic;

use App\Domain\Academic\Contracts\AcademicAudit;
use App\Domain\Academic\Contracts\LanguageProfile;
use App\Domain\Academic\Enums\LanguageRole;
use App\Domain\Academic\Http\Operator\OperatorResources;
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
use App\Domain\Academic\Models\UserLanguage;
use App\Domain\Academic\Services\NeedParser;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsAcademicStructure;
use Tests\TestCase;

class OperatorAcademicTest extends TestCase
{
    use RefreshDatabase;
    use SeedsAcademicStructure;

    /** @var list<string> */
    private array $audited = [];

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAcademic();
        $this->operator = $this->academicOperator();

        $this->app->instance(AcademicAudit::class, new class($this->audited) implements AcademicAudit
        {
            public function __construct(private array &$log) {}

            public function record(?User $actor, string $action, ?Model $subject, array $context = []): void
            {
                $this->log[] = $action;
            }
        });
    }

    public function test_only_operators_reach_the_academic_screens(): void
    {
        $this->get(route('operator.academic.home'))->assertRedirect('/login');

        $learner = User::factory()->create();
        $this->actingAs($learner)->get(route('operator.academic.home'))->assertForbidden();
        foreach (OperatorResources::keys() as $resource) {
            $this->actingAs($learner)->get(route('operator.academic.index', $resource))->assertForbidden();
            $this->actingAs($learner)->post(route('operator.academic.store', $resource), [])->assertForbidden();
        }
        $this->actingAs($learner)->get(route('operator.academic.teaching-languages.index'))->assertForbidden();
    }

    public function test_every_resource_lists_for_an_operator(): void
    {
        $this->actingAs($this->operator)->get(route('operator.academic.home'))->assertOk()->assertSee('Subject offerings');

        foreach (OperatorResources::keys() as $resource) {
            $this->actingAs($this->operator)->get(route('operator.academic.index', $resource))->assertOk()->assertSee(OperatorResources::all()[$resource]['title']);
        }
        $this->actingAs($this->operator)->get('/operator/academic/unknown')->assertNotFound();

        $level = $this->level('za-caps', 'grade-10');
        $this->actingAs($this->operator)->get(route('operator.academic.index', ['resource' => 'offerings', 'academic_level_id' => $level->id]))
            ->assertOk()->assertSee('Physical Sciences')
            ->assertViewHas('rows', fn ($rows) => $rows->total() === SubjectOffering::query()->where('academic_level_id', $level->id)->count()
                && $rows->getCollection()->every(fn ($row) => $row->academic_level_id === $level->id));
    }

    public function test_an_operator_builds_a_new_country_curriculum_level_offering_and_topic(): void
    {
        $as = $this->actingAs($this->operator);

        $as->post(route('operator.academic.store', 'countries'), ['code' => 'na', 'name' => 'Namibia', 'sort' => 20])->assertSessionHasNoErrors();
        $namibia = Country::query()->where('code', 'NA')->firstOrFail();

        $as->post(route('operator.academic.store', 'frameworks'), ['code' => 'na-nqf', 'name' => 'Namibia Qualifications Framework', 'country_id' => $namibia->id])->assertSessionHasNoErrors();
        $as->post(route('operator.academic.store', 'curricula'), [
            'code' => 'na-nssc', 'name' => 'Namibia Senior Secondary Certificate', 'short_name' => 'NSSC', 'kind' => 'school',
            'country_id' => $namibia->id, 'qualification_framework_id' => QualificationFramework::query()->where('code', 'na-nqf')->value('id'), 'sort' => 50,
        ])->assertSessionHasNoErrors();
        $curriculum = Curriculum::query()->where('code', 'na-nssc')->firstOrFail();

        $as->post(route('operator.academic.store', 'levels'), [
            'curriculum_id' => $curriculum->id, 'academic_stage_id' => AcademicStage::query()->where('code', 'fet')->value('id'),
            'code' => 'grade-11', 'name' => 'Grade 11', 'position' => 10, 'aliases' => 'NSSC ordinary, nsso',
        ])->assertSessionHasNoErrors();
        $level = AcademicLevel::query()->where('curriculum_id', $curriculum->id)->firstOrFail();
        $this->assertSame(['nssc ordinary', 'nsso'], $level->aliases);

        // Same code in the same curriculum is refused; in another curriculum it is fine (CAPS already has grade-11).
        $as->post(route('operator.academic.store', 'levels'), [
            'curriculum_id' => $curriculum->id, 'academic_stage_id' => $level->academic_stage_id, 'code' => 'grade-11', 'name' => 'Duplicate', 'position' => 20,
        ])->assertSessionHasErrors('code');

        $as->post(route('operator.academic.store', 'offerings'), ['academic_level_id' => $level->id, 'subject_id' => $this->subject('biology')->id, 'is_compulsory' => '0'])->assertSessionHasNoErrors();
        $offering = SubjectOffering::query()->where('academic_level_id', $level->id)->firstOrFail();
        $this->assertSame($curriculum->id, $offering->curriculum_id, 'The curriculum comes from the level');
        $as->post(route('operator.academic.store', 'offerings'), ['academic_level_id' => $level->id, 'subject_id' => $this->subject('biology')->id])->assertSessionHasErrors('subject_id');

        $as->post(route('operator.academic.store', 'topics'), ['curriculum_subject_level_id' => $offering->id, 'name' => 'Cell biology', 'position' => 10])->assertSessionHasNoErrors();
        $this->assertSame('cell-biology', Topic::query()->where('curriculum_subject_level_id', $offering->id)->value('slug'));
        $as->post(route('operator.academic.store', 'topics'), ['curriculum_subject_level_id' => $offering->id, 'name' => 'Cell Biology', 'position' => 20])->assertSessionHasErrors('name');

        // The new structure is live for the parser and the learner form at once.
        $parsed = app(NeedParser::class)->parse('NSSC ordinary biology');
        $this->assertSame($level->id, $parsed['levels'][0]['id']);
        $this->assertSame([$offering->id], array_column($parsed['offerings'], 'id'));

        $this->assertSame(['academic.countries.created', 'academic.frameworks.created', 'academic.curricula.created', 'academic.levels.created', 'academic.offerings.created', 'academic.topics.created'], $this->audited);
    }

    public function test_subjects_institutions_and_languages_are_edited_and_deactivated_not_deleted(): void
    {
        $as = $this->actingAs($this->operator);

        $as->post(route('operator.academic.store', 'subjects'), ['name' => 'Marine Sciences', 'slug' => 'marine-sciences', 'level' => 'high_school', 'category' => 'sciences', 'aliases' => 'marine science, Mariene Wetenskappe'])->assertSessionHasNoErrors();
        $marine = Subject::query()->where('slug', 'marine-sciences')->firstOrFail();
        $this->assertTrue($marine->is_active);
        $this->assertSame(['marine science', 'mariene wetenskappe'], $marine->aliases);
        $as->post(route('operator.academic.store', 'subjects'), ['name' => 'Again', 'slug' => 'marine-sciences', 'level' => 'high_school'])->assertSessionHasErrors('slug');

        $maths = $this->subject('mathematics');
        $as->put(route('operator.academic.update', ['subjects', $maths->id]), ['name' => 'Mathematics', 'slug' => 'mathematics', 'level' => $maths->level, 'canonical_subject_id' => $maths->id])
            ->assertSessionHasErrors('canonical_subject_id');

        $as->post(route('operator.academic.toggle', ['subjects', $marine->id]))->assertSessionHas('status');
        $this->assertFalse($marine->fresh()->is_active);
        $this->assertTrue(Subject::query()->whereKey($marine->id)->exists());

        $wits = Institution::query()->where('short_name', 'Wits')->firstOrFail();
        $as->put(route('operator.academic.update', ['institutions', $wits->id]), [
            'country_id' => $wits->country_id, 'name' => $wits->name, 'short_name' => 'WITS', 'kind' => 'university', 'is_public' => '1',
        ])->assertSessionHasNoErrors();
        $this->assertSame('WITS', $wits->fresh()->short_name);
        $as->post(route('operator.academic.store', 'institutions'), ['country_id' => $wits->country_id, 'name' => $wits->name, 'kind' => 'university'])->assertSessionHasErrors('name');

        $as->post(route('operator.academic.store', 'languages'), ['code' => 'ny', 'name_en' => 'Chichewa', 'name_native' => 'Chichewa', 'script' => 'Latn', 'modality' => 'written', 'is_official_za' => '0', 'sort' => 230])->assertSessionHasNoErrors();
        $this->assertTrue(Language::query()->where('code', 'ny')->exists());
        $as->post(route('operator.academic.store', 'languages'), ['code' => 'Not A Code', 'name_en' => 'x', 'name_native' => 'x', 'modality' => 'written', 'sort' => 1])->assertSessionHasErrors('code');

        $as->post(route('operator.academic.toggle', ['countries', Country::query()->value('id')]))->assertSessionHas('status');
        $as->post(route('operator.academic.toggle', ['frameworks', QualificationFramework::query()->value('id')]))->assertSessionHas('status');

        $this->assertContains('academic.subjects.deactivated', $this->audited);
        $this->assertContains('academic.institutions.updated', $this->audited);
    }

    public function test_teaching_language_verification(): void
    {
        $tutor = User::factory()->create(['name' => 'Thandi Tutor']);
        app(LanguageProfile::class)->syncTeachingLanguages($tutor, [['code' => 'zu', 'proficiency' => 'native']]);
        $row = UserLanguage::query()->where('user_id', $tutor->id)->firstOrFail();

        $this->actingAs($this->operator)->get(route('operator.academic.teaching-languages.index', ['state' => 'unverified']))
            ->assertOk()->assertSee('Thandi Tutor')->assertSee('First language');

        $this->actingAs($this->operator)->post(route('operator.academic.teaching-languages.verify', $row))->assertSessionHas('status');
        $this->assertTrue(app(LanguageProfile::class)->teachingLanguages($tutor)[0]->verified);
        $this->assertSame($this->operator->id, $row->fresh()->verified_by_id);

        $this->actingAs($this->operator)->post(route('operator.academic.teaching-languages.unverify', $row));
        $this->assertFalse(app(LanguageProfile::class)->teachingLanguages($tutor)[0]->verified);

        // Operators cannot verify themselves; other roles are not verifiable.
        app(LanguageProfile::class)->syncTeachingLanguages($this->operator, [['code' => 'en', 'proficiency' => 'fluent']]);
        $own = UserLanguage::query()->where('user_id', $this->operator->id)->firstOrFail();
        $this->actingAs($this->operator)->post(route('operator.academic.teaching-languages.verify', $own))->assertForbidden();

        app(LanguageProfile::class)->sync($tutor, LanguageRole::Understands, ['xh']);
        $understands = UserLanguage::query()->where('user_id', $tutor->id)->where('role', 'understands')->firstOrFail();
        $this->actingAs($this->operator)->post(route('operator.academic.teaching-languages.verify', $understands))->assertNotFound();

        $this->assertSame(['academic.teaching_language.verified', 'academic.teaching_language.unverified'], $this->audited);
    }
}
