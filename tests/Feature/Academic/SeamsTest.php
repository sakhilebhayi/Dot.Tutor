<?php

namespace Tests\Feature\Academic;

use App\Domain\Academic\Contracts\AcademicScope;
use App\Domain\Academic\Contracts\AgeBandResolver;
use App\Domain\Academic\Contracts\LanguageProfile;
use App\Domain\Academic\Enums\LanguageRole;
use App\Domain\Academic\Enums\TeachingProficiency;
use App\Domain\Academic\Models\Subject;
use App\Domain\Academic\Models\SubjectOffering;
use App\Domain\Academic\Models\UserLanguage;
use App\Domain\Academic\Services\DatabaseAcademicScope;
use App\Domain\Academic\Services\DatabaseLanguageProfile;
use App\Domain\Academic\Support\UnknownAgeBand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Concerns\SeedsAcademicStructure;
use Tests\TestCase;

class SeamsTest extends TestCase
{
    use RefreshDatabase;
    use SeedsAcademicStructure;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAcademic();
    }

    public function test_the_seams_are_bound_with_honest_defaults(): void
    {
        $this->assertInstanceOf(DatabaseAcademicScope::class, app(AcademicScope::class));
        $this->assertInstanceOf(DatabaseLanguageProfile::class, app(LanguageProfile::class));
        $this->assertInstanceOf(UnknownAgeBand::class, app(AgeBandResolver::class));
        $this->assertNull(app(AgeBandResolver::class)->bandFor(now()->subYears(10)));
    }

    public function test_teaching_scope_validation_names_the_reason_for_each_pair(): void
    {
        $scope = app(AcademicScope::class);
        $grade11 = $this->level('za-caps', 'grade-11');
        $grade2 = $this->level('za-caps', 'grade-2');
        $maths = $this->subject('mathematics');
        $accounting = $this->subject('accounting');
        $inactive = Subject::query()->create(['name' => 'Latin', 'slug' => 'latin', 'is_active' => false]);

        $result = $scope->validateTeachingScope([
            ['subject_id' => $maths->id, 'level_id' => $grade11->id],
            ['subject_id' => $accounting->id, 'level_id' => $grade2->id],
            ['subject_id' => 999999, 'level_id' => $grade11->id],
            ['subject_id' => $maths->id, 'level_id' => 999999],
            ['subject_id' => $inactive->id, 'level_id' => $grade11->id],
        ]);

        $this->assertFalse($result->passes());
        $this->assertCount(1, $result->valid);
        $this->assertSame($maths->id, $result->valid[0]['subject_id']);
        $this->assertSame(['not_offered', 'unknown_subject', 'unknown_level', 'inactive'], array_column($result->invalid, 'reason'));

        SubjectOffering::query()->where('academic_level_id', $grade11->id)->where('subject_id', $maths->id)->update(['is_active' => false]);
        $this->assertFalse($scope->isOffered($maths->id, $grade11->id));
        $this->assertSame('inactive', $scope->validateTeachingScope([['subject_id' => $maths->id, 'level_id' => $grade11->id]])->invalid[0]['reason']);
    }

    public function test_offerings_at_a_level_list_active_subjects_with_languages_last(): void
    {
        $offerings = app(AcademicScope::class)->offeringsAtLevel($this->level('za-caps', 'grade-4')->id);
        $names = $offerings->pluck('subject.name')->all();

        $this->assertSame('Life Skills', $names[0]);
        $this->assertContains('Natural Sciences and Technology', $names);
        $this->assertSame('languages', $offerings->last()->subject->category);
        $this->assertNotContains('Accounting', $names);
    }

    public function test_learner_scope_is_null_without_a_profile(): void
    {
        $this->assertNull(app(AcademicScope::class)->forLearner(User::factory()->create()));
    }

    public function test_language_holdings_by_role(): void
    {
        $languages = app(LanguageProfile::class);
        $user = User::factory()->create();

        $languages->sync($user, LanguageRole::PrefersTeaching, ['zu', 'en']);
        $languages->sync($user, LanguageRole::Understands, ['xh']);
        $languages->sync($user, LanguageRole::Learning, ['fr']);
        $languages->sync($user, LanguageRole::Interface, ['af', 'en']);

        $this->assertSame(['zu', 'en'], $languages->codes($user, LanguageRole::PrefersTeaching));
        $this->assertSame('af', $languages->interfaceLocale($user), 'One interface language only');
        $this->assertSame(['zu', 'en', 'xh'], $languages->learnerPreferences($user)->acceptable());
        $this->assertSame(['fr'], $languages->learnerPreferences($user)->learning);
        $this->assertNull($languages->learnerPreferences($user)->bilingualOk);

        $languages->sync($user, LanguageRole::PrefersTeaching, ['tn']);
        $this->assertSame(['tn'], $languages->codes($user->id, LanguageRole::PrefersTeaching));
    }

    public function test_unknown_or_signed_languages_cannot_be_an_interface_and_can_teach_in_needs_its_own_writer(): void
    {
        $languages = app(LanguageProfile::class);
        $user = User::factory()->create();

        $languages->sync($user, LanguageRole::PrefersTeaching, ['sfs']); // SASL is a teaching language
        $this->assertSame(['sfs'], $languages->codes($user, LanguageRole::PrefersTeaching));

        foreach ([fn () => $languages->sync($user, LanguageRole::Interface, ['sfs']),
            fn () => $languages->sync($user, LanguageRole::Understands, ['xx']),
            fn () => $languages->sync($user, LanguageRole::CanTeachIn, ['zu'])] as $call) {
            try {
                $call();
                $this->fail('Expected InvalidArgumentException');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_teaching_languages_keep_verification_only_while_the_claim_is_unchanged(): void
    {
        $languages = app(LanguageProfile::class);
        $tutor = User::factory()->create();
        $other = User::factory()->create();

        $languages->syncTeachingLanguages($tutor, [['code' => 'zu', 'proficiency' => 'native'], ['code' => 'en', 'proficiency' => 'fluent']]);
        $languages->syncTeachingLanguages($other, [['code' => 'af', 'proficiency' => 'working']]);
        UserLanguage::query()->where('user_id', $tutor->id)->update(['verified_at' => now()]);

        $batch = $languages->teachingLanguagesFor([$tutor->id, $other->id, 424242]);
        $this->assertSame(['zu', 'en'], array_map(fn ($l) => $l->code, $batch[$tutor->id]));
        $this->assertTrue($batch[$tutor->id][0]->verified);
        $this->assertSame(TeachingProficiency::Working, $batch[$other->id][0]->proficiency);
        $this->assertArrayNotHasKey(424242, $batch);

        // Same zu claim keeps its verification; a raised en claim loses it; dropped languages go.
        $languages->syncTeachingLanguages($tutor, [['code' => 'zu', 'proficiency' => 'native'], ['code' => 'en', 'proficiency' => 'native']]);
        $after = collect($languages->teachingLanguages($tutor))->keyBy('code');
        $this->assertTrue($after['zu']->verified);
        $this->assertFalse($after['en']->verified);

        $languages->syncTeachingLanguages($tutor, [['code' => 'xh', 'proficiency' => 'fluent']]);
        $this->assertSame(['xh'], array_map(fn ($l) => $l->code, $languages->teachingLanguages($tutor)));
    }

    public function test_holdings_are_removed_with_the_account(): void
    {
        $user = User::factory()->create();
        app(LanguageProfile::class)->sync($user, LanguageRole::Understands, ['en']);

        $user->delete();

        $this->assertSame(0, UserLanguage::query()->count());
    }
}
