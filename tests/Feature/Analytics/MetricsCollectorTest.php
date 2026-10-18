<?php

namespace Tests\Feature\Analytics;

use App\Models\Subject;
use App\Models\Team;
use App\Models\TutorProfile;
use App\Models\User;
use App\Services\Analytics\MetricsCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsMarketplace;
use Tests\TestCase;

class MetricsCollectorTest extends TestCase
{
    use BuildsMarketplace;
    use RefreshDatabase;

    private const NOW = '2026-11-05 09:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::NOW);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function collect(Team $team): array
    {
        return app(MetricsCollector::class)->collect($team);
    }

    /** @return array{0: TutorProfile, 1: User, 2: Subject} */
    private function tutorWithStudent(Team $team): array
    {
        $profile = $this->makeTutorIn($team);

        return [$profile, $this->makeUser('Student'), $profile->subjects()->first()];
    }

    public function test_sessions_booked_in_the_last_seven_days_count_whatever_became_of_them(): void
    {
        $team = $this->makeCompany();
        [$profile, $student, $subject] = $this->tutorWithStudent($team);

        foreach (['pending', 'confirmed', 'completed', 'cancelled', 'no_show'] as $i => $status) {
            $this->stampSession($this->makeSession($profile, $student, $subject, ['status' => $status, 'starts_at' => now()->addDays(10 + $i)]), '2026-11-02 12:00:00');
        }

        $this->assertSame(5, $this->collect($team)['metrics']['sessions_7d']);
    }

    public function test_the_seven_day_window_is_utc_inclusive_at_the_start_and_never_looks_ahead(): void
    {
        $team = $this->makeCompany();
        [$profile, $student, $subject] = $this->tutorWithStudent($team);
        $mk = fn (string $createdAt, int $offset) => $this->stampSession($this->makeSession($profile, $student, $subject, ['starts_at' => now()->addDays(20 + $offset)]), $createdAt);

        $mk('2026-10-29 09:00:00', 1); // exactly seven days ago: in
        $mk('2026-10-29 08:59:59', 2); // one second earlier: out
        $mk('2026-11-05 09:00:00', 3); // right now: in
        $mk('2026-11-05 09:00:01', 4); // in the future (clock skew): out

        $this->assertSame(2, $this->collect($team)['metrics']['sessions_7d']);
    }

    public function test_completed_counts_sessions_that_became_completed_inside_the_window(): void
    {
        $team = $this->makeCompany();
        [$profile, $student, $subject] = $this->tutorWithStudent($team);
        $mk = fn (string $status, string $updatedAt, int $offset) => $this->stampSession(
            $this->makeSession($profile, $student, $subject, ['status' => $status, 'starts_at' => now()->addDays(20 + $offset)]),
            '2026-09-01 10:00:00', $updatedAt,
        );

        $mk('completed', '2026-11-04 10:00:00', 1);  // booked long ago, completed yesterday: in
        $mk('completed', '2026-10-29 09:00:00', 2);  // completed exactly seven days ago: in
        $mk('completed', '2026-10-29 08:59:59', 3);  // just outside: out
        $mk('confirmed', '2026-11-04 10:00:00', 4);  // not completed: out
        $mk('cancelled', '2026-11-04 10:00:00', 5);  // out

        $metrics = $this->collect($team)['metrics'];

        $this->assertSame(2, $metrics['completed_7d']);
        $this->assertSame(0, $metrics['sessions_7d'], 'all were booked weeks ago');
    }

    public function test_active_tutors_are_approved_profiles_only(): void
    {
        $team = $this->makeCompany();
        $this->makeTutorIn($team, ['status' => 'approved']);
        $this->makeTutorIn($team, ['status' => 'approved']);
        foreach (['pending', 'rejected', 'suspended'] as $status) {
            $this->makeTutorIn($team, ['status' => $status]);
        }
        $this->makeUser('Student only'); // a member with no tutor profile at all
        $teamless = $this->makeTutor(['status' => 'approved'], null, User::factory()->create(['current_team_id' => null]));

        $this->assertSame(2, $this->collect($team)['metrics']['active_tutors']);
        $this->assertNotNull($teamless);
    }

    public function test_a_session_counts_for_the_tutors_current_team_and_moves_with_them(): void
    {
        $a = $this->makeCompany();
        $b = $this->makeCompany();
        [$profile, $student, $subject] = $this->tutorWithStudent($a);
        $this->makeSession($profile, $student, $subject);

        $this->assertSame(1, $this->collect($a)['metrics']['sessions_7d']);
        $this->assertSame(0, $this->collect($b)['metrics']['sessions_7d']);

        $profile->user->forceFill(['current_team_id' => $b->id])->save();

        $this->assertSame(0, $this->collect($a)['metrics']['sessions_7d']);
        $this->assertSame(1, $this->collect($b)['metrics']['sessions_7d']);
        $this->assertSame(0, $this->collect($a)['metrics']['active_tutors']);
        $this->assertSame(1, $this->collect($b)['metrics']['active_tutors']);
    }

    public function test_the_students_team_is_irrelevant(): void
    {
        $a = $this->makeCompany();
        $b = $this->makeCompany();
        $profile = $this->makeTutorIn($a);
        $student = User::factory()->create(['current_team_id' => $b->id]);
        $this->makeSession($profile, $student, $profile->subjects()->first());

        $this->assertSame(1, $this->collect($a)['metrics']['sessions_7d']);
        $this->assertSame(0, $this->collect($b)['metrics']['sessions_7d']);
    }

    public function test_company_a_numbers_never_include_company_b_rows(): void
    {
        $a = $this->makeCompany();
        $b = $this->makeCompany();
        [$pa, $sa, $suba] = $this->tutorWithStudent($a);
        [$pb, $sb, $subb] = $this->tutorWithStudent($b);
        $this->makeTutorIn($b);
        $this->makeSession($pa, $sa, $suba, ['status' => 'completed']);
        foreach (range(1, 4) as $i) {
            $this->makeSession($pb, $sb, $subb, ['status' => 'completed', 'starts_at' => now()->addDays(10 + $i)]);
        }

        $this->assertSame(['sessions_7d' => 1, 'completed_7d' => 1, 'active_tutors' => 1], $this->collect($a)['metrics']);
        $this->assertSame(['sessions_7d' => 4, 'completed_7d' => 4, 'active_tutors' => 2], $this->collect($b)['metrics']);
    }

    public function test_the_numbers_do_not_depend_on_who_is_signed_in(): void
    {
        $a = $this->makeCompany();
        $b = $this->makeCompany();
        [$pb, $sb, $subb] = $this->tutorWithStudent($b);
        $this->makeSession($pb, $sb, $subb);

        $anonymous = $this->collect($b);

        Auth::login($a->owner);
        $asOther = $this->collect($b);
        Auth::logout();
        Auth::login(User::factory()->create(['current_team_id' => null]));
        $asTeamless = $this->collect($b);

        $this->assertSame(1, $anonymous['metrics']['sessions_7d']);
        $this->assertSame($anonymous, $asOther);
        $this->assertSame($anonymous, $asTeamless);
    }

    public function test_an_empty_company_reports_true_zeros(): void
    {
        $this->assertSame(['sessions_7d' => 0, 'completed_7d' => 0, 'active_tutors' => 0], $this->collect($this->makeCompany())['metrics']);
        $this->assertSame([], $this->collect($this->makeCompany())['omitted']);
    }

    public function test_only_company_teams_are_published_for(): void
    {
        $company = $this->makeCompany();
        $unlinked = $this->makeCompany();
        $unlinked->forceFill(['infodot_team_uuid' => null])->save();
        $blank = $this->makeCompany();
        $blank->forceFill(['infodot_team_uuid' => ''])->save();
        $personalLinked = $this->makeUser('Solo')->currentTeam;
        $personalLinked->forceFill(['infodot_team_uuid' => '11111111-2222-4333-8444-555555555555'])->save();

        $collector = app(MetricsCollector::class);

        $this->assertSame([$company->id], $collector->companies()->pluck('id')->all());
        $this->assertSame([$company->id], $collector->companies($company->infodot_team_uuid)->pluck('id')->all());
        $this->assertSame([], $collector->companies($personalLinked->infodot_team_uuid)->pluck('id')->all(), 'a personal team is never a company, even when linked');
    }

    public function test_it_only_ever_returns_keys_defined_in_the_analytics_contract(): void
    {
        $team = $this->makeCompany();
        [$profile, $student, $subject] = $this->tutorWithStudent($team);
        $this->makeSession($profile, $student, $subject, ['status' => 'completed']);

        $metrics = $this->collect($team)['metrics'];

        // Dot.Analytics config/metrics.php, platforms.tutor.metrics. No money, so no `currency`.
        $contract = ['sessions_7d', 'completed_7d', 'active_tutors'];
        $this->assertSame($contract, array_keys($metrics));
        foreach ($metrics as $key => $value) {
            $this->assertIsInt($value, $key);
            $this->assertGreaterThanOrEqual(0, $value, $key);
        }
    }

    public function test_the_collector_runs_a_fixed_number_of_queries(): void
    {
        $team = $this->makeCompany();
        [$profile, $student, $subject] = $this->tutorWithStudent($team);
        foreach (range(1, 20) as $i) {
            $this->makeSession($profile, $student, $subject, ['starts_at' => now()->addDays(30 + $i)]);
        }

        DB::enableQueryLog();
        $this->collect($team);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(3, $queries);
    }
}
