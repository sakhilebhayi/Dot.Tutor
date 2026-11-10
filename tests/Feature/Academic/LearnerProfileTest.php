<?php

namespace Tests\Feature\Academic;

use App\Domain\Academic\Contracts\AcademicScope;
use App\Domain\Academic\Contracts\AgeBandResolver;
use App\Domain\Academic\Contracts\LanguageProfile;
use App\Domain\Academic\Enums\LanguageRole;
use App\Domain\Academic\Models\Country;
use App\Domain\Academic\Models\Curriculum;
use App\Domain\Academic\Models\Institution;
use App\Domain\Academic\Models\LearnerProfile;
use App\Domain\Academic\Models\SubjectOffering;
use App\Domain\Academic\Services\LearnerProfiles;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsAcademicStructure;
use Tests\TestCase;

class LearnerProfileTest extends TestCase
{
    use RefreshDatabase;
    use SeedsAcademicStructure;

    private User $learner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAcademic();
        $this->learner = User::factory()->create();
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'country_id' => Country::query()->where('code', 'ZA')->value('id'),
            'academic_level_id' => $this->level('za-caps', 'grade-11')->id,
            'subjects' => [$this->subject('mathematics')->id, $this->subject('physical-sciences')->id],
            'birth_year' => now()->subYears(16)->year,
            'birth_month' => 3,
            'teaching' => ['zu', 'en'],
            'understands' => ['en', 'xh'],
            'learning' => [],
            'bilingual_ok' => '1',
        ], $overrides);
    }

    public function test_the_profile_page_needs_sign_in_and_lists_levels_by_curriculum(): void
    {
        $this->get(route('academic.profile.edit'))->assertRedirect('/login');

        $this->actingAs($this->learner)->get(route('academic.profile.edit'))
            ->assertOk()
            ->assertSee('Your learning profile')
            ->assertSee('label="CAPS"', false)
            ->assertSee('label="Cambridge"', false)
            ->assertSee('Choose your curriculum and level');
    }

    public function test_choosing_a_level_shows_its_subjects_and_tertiary_levels_ask_for_an_institution(): void
    {
        $this->actingAs($this->learner)->get(route('academic.profile.edit', ['level' => $this->level('za-caps', 'grade-4')->id]))
            ->assertOk()
            ->assertSee('Natural Sciences and Technology')
            ->assertDontSee('Physical Sciences')
            ->assertDontSee('University or college');

        $this->actingAs($this->learner)->get(route('academic.profile.edit', ['level' => $this->level('za-higher-education', 'honours')->id]))
            ->assertOk()
            ->assertSee('Research Methods')
            ->assertSee('University of Cape Town');
    }

    public function test_describe_what_you_need_prefills_the_form(): void
    {
        $response = $this->actingAs($this->learner)->get(route('academic.profile.edit', ['need' => 'Grade 11 Mathematics in isiZulu']));

        $response->assertOk()->assertSee('We filled in what we recognised');
        $html = $response->getContent();
        $this->assertMatchesRegularExpression('/name="academic_level_id" value="'.$this->level('za-caps', 'grade-11')->id.'"/', $html);
        $this->assertMatchesRegularExpression('/value="'.$this->subject('mathematics')->id.'" checked/', $html);
        $this->assertMatchesRegularExpression('/name="teaching\[\]" value="zu" checked/', $html);

        $this->actingAs($this->learner)->get(route('academic.profile.edit', ['need' => 'something vague']))
            ->assertSee('We could not recognise a level or subject');
    }

    public function test_saving_stores_the_minimum_and_feeds_the_seams(): void
    {
        $this->actingAs($this->learner)->put(route('academic.profile.update'), $this->payload())
            ->assertRedirect(route('academic.profile.edit'))
            ->assertSessionHas('status');

        $profile = LearnerProfile::query()->where('user_id', $this->learner->id)->firstOrFail();
        $this->assertSame(1, $profile->birth_month->day, 'Only the month is kept');
        $this->assertSame(3, $profile->birth_month->month);
        $this->assertSame('31', $profile->ageBandBirthDate()->format('d'), 'Age banding uses the last day of the month');
        $this->assertTrue($profile->bilingual_ok);
        $this->assertNotNull($profile->onboarded_at);
        $this->assertNull($profile->institution_id);

        $scope = app(AcademicScope::class)->forLearner($this->learner);
        $this->assertSame('za-caps', $scope->curriculumCode);
        $this->assertSame('grade-11', $scope->levelCode);
        $this->assertSame('fet', $scope->stageCode);
        $this->assertEqualsCanonicalizing([$this->subject('mathematics')->id, $this->subject('physical-sciences')->id], $scope->subjectIds);
        $this->assertTrue($scope->onboarded);
        $this->assertNotNull($profile->subjects->first()->pivot->curriculum_subject_level_id);

        $languages = app(LanguageProfile::class);
        $this->assertSame(['zu', 'en'], $languages->codes($this->learner, LanguageRole::PrefersTeaching));
        $this->assertSame(['xh'], $languages->codes($this->learner, LanguageRole::Understands), 'Teaching languages are not repeated as understood');
        $this->assertTrue($languages->learnerPreferences($this->learner)->bilingualOk);
    }

    public function test_the_form_re_renders_what_was_saved(): void
    {
        $this->actingAs($this->learner)->put(route('academic.profile.update'), $this->payload());

        $html = $this->actingAs($this->learner)->get(route('academic.profile.edit'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/value="'.$this->subject('physical-sciences')->id.'" checked/', $html);
        $this->assertMatchesRegularExpression('/name="birth_month"[^>]*>.*?value="3" selected/s', $html);
    }

    public function test_a_school_learner_never_gets_an_institution_and_a_tertiary_learner_may(): void
    {
        $uct = Institution::query()->where('short_name', 'UCT')->value('id');

        $this->actingAs($this->learner)->put(route('academic.profile.update'), $this->payload(['institution_id' => $uct]));
        $this->assertNull(LearnerProfile::query()->value('institution_id'));

        $this->actingAs($this->learner)->put(route('academic.profile.update'), $this->payload([
            'academic_level_id' => $this->level('za-higher-education', 'undergraduate-2')->id,
            'subjects' => [$this->subject('statistics')->id],
            'birth_year' => now()->subYears(20)->year,
            'institution_id' => $uct,
        ]))->assertSessionHasNoErrors();
        $this->assertSame($uct, LearnerProfile::query()->value('institution_id'));
    }

    public function test_validation_rejects_mismatched_structure_and_impossible_dates(): void
    {
        $this->actingAs($this->learner)->put(route('academic.profile.update'), $this->payload(['subjects' => [$this->subject('accounting')->id], 'academic_level_id' => $this->level('za-caps', 'grade-2')->id]))
            ->assertSessionHasErrors('subjects');

        $elsewhere = Country::query()->create(['code' => 'NA', 'name' => 'Namibia']);
        $this->actingAs($this->learner)->put(route('academic.profile.update'), $this->payload(['country_id' => $elsewhere->id]))
            ->assertSessionHasErrors('academic_level_id');

        // An international curriculum is fine anywhere.
        $this->actingAs($this->learner)->put(route('academic.profile.update'), $this->payload([
            'country_id' => $elsewhere->id, 'academic_level_id' => $this->level('cambridge', 'igcse')->id, 'subjects' => [$this->subject('biology')->id],
        ]))->assertSessionHasNoErrors();

        $nextMonth = now()->startOfMonth()->addMonth();
        $this->actingAs($this->learner)->put(route('academic.profile.update'), $this->payload(['birth_year' => $nextMonth->year, 'birth_month' => $nextMonth->month]))
            ->assertSessionHasErrors($nextMonth->year > now()->year ? 'birth_year' : 'birth_month');
        $this->actingAs($this->learner)->put(route('academic.profile.update'), $this->payload(['birth_year' => null]))->assertSessionHasErrors('birth_year');
        $this->actingAs($this->learner)->put(route('academic.profile.update'), $this->payload(['teaching' => ['xx']]))->assertSessionHasErrors('teaching.0');

        Curriculum::query()->where('code', 'za-caps')->update(['is_active' => false]);
        $this->actingAs($this->learner)->put(route('academic.profile.update'), $this->payload())->assertSessionHasErrors('academic_level_id');
    }

    public function test_age_banding_is_handed_to_the_safety_seam(): void
    {
        $this->actingAs($this->learner)->put(route('academic.profile.update'), $this->payload());
        $profile = LearnerProfile::query()->firstOrFail();

        $this->assertNull(app(LearnerProfiles::class)->ageBand($profile), 'Unknown until the safety domain is bound');

        $this->app->bind(AgeBandResolver::class, fn () => new class implements AgeBandResolver
        {
            public function bandFor(?CarbonInterface $birthDate): ?string
            {
                return $birthDate && $birthDate->diffInYears(now()) < 18 ? 'teen' : 'adult';
            }
        });
        $this->assertSame('teen', app(LearnerProfiles::class)->ageBand($profile));
    }

    public function test_the_profile_goes_with_the_account(): void
    {
        $this->actingAs($this->learner)->put(route('academic.profile.update'), $this->payload());

        $this->learner->delete();

        $this->assertSame(0, LearnerProfile::query()->count());
        $this->assertSame(0, \DB::table('learner_profile_subjects')->count());
    }

    public function test_structure_json_for_pickers(): void
    {
        $this->actingAs($this->learner)->getJson(route('academic.curricula', ['country' => Country::query()->value('id')]))
            ->assertOk()->assertJsonPath('data.0.code', 'za-caps')->assertJsonPath('data.0.levels.0.name', 'Grade 1');

        $this->actingAs($this->learner)->getJson(route('academic.offerings', $this->level('za-caps', 'grade-12')))
            ->assertOk()->assertJsonFragment(['name' => 'Mathematics']);

        $offering = SubjectOffering::query()->where('academic_level_id', $this->level('za-caps', 'grade-10')->id)
            ->where('subject_id', $this->subject('physical-sciences')->id)->firstOrFail();
        $this->actingAs($this->learner)->getJson(route('academic.topics', $offering))
            ->assertOk()->assertJsonPath('data.3.name', 'Mechanics')->assertJsonPath('data.3.children.0.name', 'Vectors and scalars');
    }
}
