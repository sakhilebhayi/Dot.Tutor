<?php

namespace Tests\Feature\Marketplace;

use App\Domain\Marketplace\Contracts\AcademicScopePort;
use App\Domain\Marketplace\Contracts\LanguageProfilePort;
use App\Domain\Marketplace\Models\TutorTeachingScope;
use App\Domain\Marketplace\Seams\NullAcademicScope;
use App\Domain\Marketplace\Seams\NullLanguageProfile;
use App\Domain\Marketplace\Services\MatchingService;
use App\Domain\Marketplace\Support\LearningNeed;
use App\Models\SessionRating;
use App\Models\Subject;
use App\Models\TutorProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\BuildsOfferings;
use Tests\TestCase;

class MatchingTest extends TestCase
{
    use BuildsOfferings;
    use RefreshDatabase;

    private Subject $maths;

    protected function setUp(): void
    {
        parent::setUp();
        $this->maths = $this->makeSubject('Mathematics');
    }

    private function tutor(string $name, array $offering = [], array $profile = []): TutorProfile
    {
        $tutor = $this->makeTutor(array_merge(['headline' => $name.' teaches maths'], $profile), $this->maths, $this->makeUser($name));
        $this->openWeek($tutor, '14:00', '20:00');
        $this->makeOffering($tutor, $this->maths, $offering);

        return $tutor;
    }

    private function scope(TutorProfile $tutor, ?int $min, ?int $max, ?int $curriculum = 1): void
    {
        $this->stubAcademicIds($curriculum, [$min, $max]);
        TutorTeachingScope::create(['tutor_profile_id' => $tutor->id, 'subject_id' => $this->maths->id, 'curriculum_id' => $curriculum, 'level_min_id' => $min, 'level_max_id' => $max]);
    }

    private function reviews(TutorProfile $tutor, int $count, int $stars): void
    {
        foreach (range(1, $count) as $i) {
            $session = $this->makeSession($tutor, $this->makeUser("Reviewer {$i}"), $this->maths, ['status' => 'completed', 'starts_at' => now()->subDays(30 + $i)]);
            SessionRating::create(['session_id' => $session->id, 'rated_by' => $session->student_id, 'rating' => $stars]);
        }
    }

    private function match(array $need): array
    {
        $result = app(MatchingService::class)->match(LearningNeed::fromArray(['subject_id' => $this->maths->id] + $need));

        return $result;
    }

    private function names(array $result): array
    {
        return array_map(fn ($r) => $r['tutor']['name'], $result['results']);
    }

    public function test_the_better_fit_ranks_first_and_every_result_explains_itself(): void
    {
        $this->fakeAcademic();
        $this->fakeLanguages([]);
        $fit = $this->tutor('Thandi', ['price_minor' => 25000]);
        $this->scope($fit, 10, 12);
        $off = $this->tutor('Pieter', ['price_minor' => 25000]);
        $this->scope($off, 4, 9);

        $result = $this->match(['level_id' => 11, 'curriculum_id' => 1, 'budget_per_hour_minor' => 30000, 'currency' => 'ZAR']);

        // Pieter teaches Grades 4-9: two levels away from Grade 11, so he is left out entirely.
        $this->assertSame(['Thandi'], $this->names($result));
        $top = $result['results'][0];
        $this->assertContains('Teaches this subject at Grade 11.', $top['reasons']);
        $this->assertContains('Teaches the CAPS curriculum.', $top['reasons']);
        $this->assertSame(MatchingService::TERMS, array_column($top['terms'], 'term'));
        $this->assertEqualsWithDelta($top['score'], array_sum(array_column($top['terms'], 'points')), 0.05);
        $this->assertSame(1, $result['excluded']);
    }

    public function test_matching_is_deterministic(): void
    {
        foreach (['A', 'B', 'C', 'D', 'E'] as $name) {
            $this->tutor($name);
        }
        $need = ['format' => 'online'];

        $this->assertSame($this->names($this->match($need)), $this->names($this->match($need)));
        // Equal fits are not simply ordered by id (oldest first) or name.
        $orders = collect([['format' => 'online'], ['format' => 'either'], ['duration_minutes' => 30], ['duration_minutes' => 45], ['duration_minutes' => 90]])
            ->map(fn ($n) => implode('', $this->names($this->match($n))))->unique();
        $this->assertGreaterThan(1, $orders->count());
    }

    public function test_over_budget_tutors_are_excluded_and_a_little_over_ranks_lower(): void
    {
        $this->tutor('Cheap', ['price_minor' => 20000]);
        $this->tutor('Slightly over', ['price_minor' => 33000]);
        $this->tutor('Expensive', ['price_minor' => 60000]);

        $result = $this->match(['budget_per_hour_minor' => 30000]);

        $this->assertSame(['Cheap', 'Slightly over'], $this->names($result));
        $this->assertStringContainsString('A little over your budget', implode(' ', $result['results'][1]['reasons']));
    }

    public function test_teaching_language_filters_and_bilingual_tutors_fit(): void
    {
        $zulu = $this->tutor('Zulu speaker');
        $english = $this->tutor('English only');
        $both = $this->tutor('Bilingual');
        $unlisted = $this->tutor('Not listed');
        $this->fakeLanguages([
            $zulu->user_id => [['code' => 'zu', 'name' => 'isiZulu', 'level' => 'native', 'verified' => true]],
            $english->user_id => [['code' => 'en', 'name' => 'English', 'level' => 'native', 'verified' => false]],
            $both->user_id => [['code' => 'zu', 'name' => 'isiZulu', 'level' => 'native', 'verified' => false], ['code' => 'en', 'name' => 'English', 'level' => 'fluent', 'verified' => false]],
        ]);

        $onlyZulu = $this->names($this->match(['languages' => ['zu']]));
        $this->assertNotContains('English only', $onlyZulu);
        $this->assertContains('Zulu speaker', $onlyZulu);
        $this->assertContains('Not listed', $onlyZulu, 'A tutor who has not listed languages is ranked lower, not hidden.');

        $result = $this->match(['languages' => ['zu', 'en'], 'bilingual_ok' => true]);
        $bilingual = collect($result['results'])->firstWhere('tutor.name', 'Bilingual');
        $this->assertContains('Can teach bilingually in isiZulu and English.', $bilingual['reasons']);
        $language = collect($bilingual['terms'])->firstWhere('term', 'language');
        $this->assertSame(1.0, $language['value']);
        $englishOnly = collect($result['results'])->firstWhere('tutor.name', 'English only');
        $this->assertSame(0.7, collect($englishOnly['terms'])->firstWhere('term', 'language')['value']);
    }

    public function test_language_uses_teaching_languages_not_the_interface_locale(): void
    {
        $tutor = $this->tutor('Sesotho tutor');
        $this->fakeLanguages([$tutor->user_id => [['code' => 'st', 'name' => 'Sesotho', 'level' => 'native', 'verified' => false]]]);
        app()->setLocale('zu');

        $this->assertSame([], $this->names($this->match(['languages' => ['zu']])));
        $this->assertSame(['Sesotho tutor'], $this->names($this->match(['languages' => ['st']])));
    }

    public function test_without_language_or_academic_data_the_result_says_so(): void
    {
        // The marketplace's null defaults (the integration provider binds the real academic seams over them).
        $this->app->bind(LanguageProfilePort::class, NullLanguageProfile::class);
        $this->app->bind(AcademicScopePort::class, NullAcademicScope::class);
        $this->tutor('Anyone');

        $result = $this->match(['languages' => ['xh'], 'level_id' => 11]);

        $this->assertSame(['Anyone'], $this->names($result));
        $this->assertCount(2, $result['notes']);
        $this->assertContains('Teaching languages are not recorded yet.', $result['results'][0]['reasons']);
    }

    public function test_format_and_area_are_hard_requirements(): void
    {
        $this->tutor('Online only');
        $this->tutor('Soweto', ['format' => 'in_person', 'area' => 'Soweto'], ['teaches_online' => false, 'teaches_in_person' => true, 'in_person_area' => 'Soweto, Johannesburg']);
        $this->tutor('Durban', ['format' => 'in_person', 'area' => 'Durban'], ['teaches_online' => false, 'teaches_in_person' => true, 'in_person_area' => 'Durban']);

        $this->assertSame(['Soweto'], $this->names($this->match(['format' => 'in_person', 'area' => 'soweto'])));
        $this->assertSame(['Online only'], $this->names($this->match(['format' => 'online'])));
    }

    public function test_availability_comes_from_the_real_slot_finder(): void
    {
        $evenings = $this->tutor('Evenings');
        $mornings = $this->makeTutor(['headline' => 'Mornings'], $this->maths, $this->makeUser('Mornings'));
        $this->openWeek($mornings, '06:00', '09:00');
        $this->makeOffering($mornings, $this->maths);

        $result = $this->match(['from_time' => '15:00', 'to_time' => '19:00', 'timezone' => 'UTC']);

        $this->assertSame('Evenings', $result['results'][0]['tutor']['name']);
        $morning = collect($result['results'])->firstWhere('tutor.name', 'Mornings');
        $this->assertSame(0.0, collect($morning['terms'])->firstWhere('term', 'availability')['value']);
        $this->assertNotEmpty($result['results'][0]['next_slots']);
    }

    public function test_reviews_count_only_from_the_minimum_and_new_tutors_get_a_capped_lift(): void
    {
        $veteran = $this->tutor('Veteran', [], ['reviewed_at' => now()->subYears(2)]);
        $this->reviews($veteran, 10, 5);
        $twoReviews = $this->tutor('Two reviews', [], ['reviewed_at' => now()->subYears(1)]);
        $this->reviews($twoReviews, 2, 5);
        $newcomer = $this->tutor('Newcomer', [], ['reviewed_at' => now()->subDays(3)]);

        $result = collect($this->match([])['results'])->keyBy('tutor.name');

        $this->assertSame(7.0, collect($result['Veteran']['terms'])->firstWhere('term', 'reviews')['points']);
        $this->assertSame(0.0, collect($result['Two reviews']['terms'])->firstWhere('term', 'reviews')['points']);
        $newTerm = collect($result['Newcomer']['terms'])->firstWhere('term', 'new_tutor');
        $this->assertSame(5.0, $newTerm['points']);
        // The lift is capped below the review term: a strong record still ranks above a newcomer.
        $this->assertGreaterThan($result['Newcomer']['score'], $result['Veteran']['score']);
        $this->assertGreaterThan($result['Two reviews']['score'], $result['Newcomer']['score']);
    }

    public function test_verification_level_is_a_term_and_there_is_no_paid_boost(): void
    {
        $verified = $this->tutor('Verified');
        $verified->forceFill(['verification_level' => 'professional_educator'])->save();
        $this->tutor('Unverified');

        $result = collect($this->match([])['results'])->keyBy('tutor.name');
        $this->assertSame(8.0, collect($result['Verified']['terms'])->firstWhere('term', 'verification')['points']);
        $this->assertContains('Not yet verified by Dot.Tutor.', $result['Unverified']['reasons']);

        foreach (['boost', 'boosted', 'promoted', 'sponsored', 'featured', 'paid_rank'] as $column) {
            $this->assertFalse(Schema::hasColumn('tutor_profiles', $column), "tutor_profiles.{$column} must not exist");
        }
    }

    public function test_group_requests_need_a_group_offering_with_open_seats(): void
    {
        $this->tutor('One to one only');
        $group = $this->tutor('Group tutor', ['type' => 'group', 'capacity' => 5, 'title' => 'Group']);

        $names = $this->names($this->match(['help_type' => 'group']));
        $this->assertSame(['Group tutor'], $names);
        $this->assertSame(0.0, collect($this->match(['help_type' => 'group'])['results'][0]['terms'])->firstWhere('term', 'availability')['value']);
        $this->assertNotNull($group);
    }

    public function test_the_api_match_endpoint_validates_and_excludes_the_learner_themself(): void
    {
        $self = $this->tutor('Me');
        $this->tutor('Someone else');

        $this->actingAs($self->user)->postJson('/api/tutor/match', [])->assertStatus(422)->assertJsonPath('error', 'validation_failed');
        $response = $this->actingAs($self->user)->postJson('/api/tutor/match', ['subject_id' => $this->maths->id, 'format' => 'online'])->assertOk();

        $this->assertSame(['Someone else'], array_column(array_column($response->json('results'), 'tutor'), 'name'));
    }
}
