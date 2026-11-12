<?php

namespace Tests\Feature\Perf;

use App\Domain\Academic\Contracts\LanguageProfile;
use App\Domain\Academic\Services\ReferenceDataSync;
use App\Domain\Marketplace\Services\MatchingService;
use App\Domain\Marketplace\Support\LearningNeed;
use App\Domain\Ops\Access\OpsRole;
use App\Domain\Ops\Access\RoleAdministration;
use App\Domain\Safety\Models\AgeRecord;
use App\Domain\Safety\Services\AgeResolver;
use App\Models\Subject;
use App\Models\TutorProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsOfferings;
use Tests\TestCase;

/**
 * List pages must not cost a query per row (R-47 D-1602 to D-1606, docs/R47_PERF.md section 2): the statement count
 * of each list is the same with a few rows as with many. Before the fixes, matching ran four statements per candidate
 * tutor, the people list nine per person and the bookings list five per lesson.
 */
class ListQueryBudgetTest extends TestCase
{
    use BuildsOfferings;
    use MeasuresRequests;
    use RefreshDatabase;

    private Subject $maths;

    protected function setUp(): void
    {
        parent::setUp();
        $this->listenToQueries();
        $this->maths = $this->makeSubject('Mathematics');
    }

    private function tutors(int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            $user = $this->makeUser('Tutor '.uniqid());
            $tutor = $this->makeTutor(['headline' => 'Maths tutor'], $this->maths, $user);
            $this->openWeek($tutor, '14:00', '20:00');
            $this->makeOffering($tutor, $this->maths);
            app(LanguageProfile::class)->syncTeachingLanguages($user, [['code' => 'zu', 'proficiency' => 'native'], ['code' => 'en', 'proficiency' => 'fluent']]);
        }
    }

    private function matchStatements(): int
    {
        $need = LearningNeed::fromArray(['subject_id' => $this->maths->id, 'languages' => ['zu'], 'from_time' => '15:00', 'to_time' => '19:00']);

        return $this->statementsOf(fn () => app(MatchingService::class)->match($need));
    }

    public function test_matching_costs_the_same_statements_for_three_tutors_as_for_fifteen(): void
    {
        app(ReferenceDataSync::class)->run(); // languages and levels
        $this->tutors(3);
        $this->matchStatements();
        $few = $this->matchStatements();
        $this->tutors(12);
        $many = $this->matchStatements();

        $this->assertSame(15, TutorProfile::query()->count());
        $this->assertSame($few, $many, "matching statements with 3 tutors ({$few}) and with 15 ({$many})");
        $this->assertLessThanOrEqual(12, $many);
    }

    public function test_the_ops_people_list_costs_the_same_for_five_people_as_for_twenty(): void
    {
        $admin = User::factory()->create(['name' => 'Ops Admin']);
        app(RoleAdministration::class)->setRoles(null, $admin, [OpsRole::Admin->value]);
        $people = function (int $n) {
            foreach (range(1, $n) as $i) {
                $user = User::factory()->create();
                // Half with a recorded birth date, half unknown (the unknown ones take the attested-adult checks).
                if ($i % 2 === 0) {
                    AgeRecord::query()->create(['user_id' => $user->id, 'birth_date' => now()->subYears(10 + $i)->toDateString(), 'source' => 'operator_verified']);
                }
            }
        };

        $people(4);
        $this->actingAs($admin->fresh());
        $this->get(route('ops.users'))->assertOk(); // warm: first-request work (session, caches) is not per row
        $few = $this->statementsOf(fn () => $this->get(route('ops.users'))->assertOk());
        $people(15);
        $many = $this->statementsOf(fn () => $this->get(route('ops.users'))->assertOk());

        $this->assertSame($few, $many, "people list statements with 5 people ({$few}) and with 20 ({$many})");
    }

    public function test_my_bookings_costs_the_same_for_two_lessons_as_for_twelve(): void
    {
        [$tutor, $subject] = $this->bookableTutor([], [], $this->maths);
        $learner = $this->makeUser('Learner');
        $lessons = function (int $n) use ($tutor, $learner, $subject) {
            foreach (range(1, $n) as $i) {
                $this->makeSession($tutor, $learner, $subject, ['status' => 'completed', 'starts_at' => now()->subDays($i + random_int(0, 300))]);
            }
        };

        $lessons(2);
        $this->actingAs($learner);
        $this->get(route('marketplace.bookings'))->assertOk();
        $few = $this->statementsOf(fn () => $this->get(route('marketplace.bookings'))->assertOk());
        $lessons(10);
        $many = $this->statementsOf(fn () => $this->get(route('marketplace.bookings'))->assertOk());

        $this->assertSame($few, $many, "bookings list statements with 2 lessons ({$few}) and with 12 ({$many})");
    }

    public function test_a_primed_age_resolver_answers_every_listed_person_without_another_query(): void
    {
        $people = User::factory()->count(6)->create();
        AgeRecord::query()->create(['user_id' => $people[0]->id, 'birth_date' => now()->subYears(12)->toDateString(), 'source' => 'operator_verified']);
        $tutor = $this->makeTutor([], $this->maths, $people[1]); // approved tutor: attested adult
        $resolver = app(AgeResolver::class);
        $resolver->prime($people);

        $bands = [];
        $statements = $this->statementsOf(function () use ($resolver, $people, &$bands) {
            foreach ($people as $person) {
                $bands[] = $resolver->band($person)->value;
            }
        });

        $this->assertSame(0, $statements, 'band() for primed people reads nothing more');
        $this->assertSame('child', $bands[0]);
        $this->assertSame('adult', $bands[1]);
        $this->assertSame(app(AgeResolver::class)->band($people[2])->value, $bands[2], 'primed and unprimed agree');
        unset($tutor);
    }
}
