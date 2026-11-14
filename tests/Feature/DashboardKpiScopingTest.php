<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DashboardMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsMarketplace;
use Tests\TestCase;

/**
 * The headline figures used to be platform-wide for every signed-in user,
 * including revenue. They are now scoped to the viewer's role. The older /dashboard page is retired (R-51: it
 * redirects to the role home), so the scoping is asserted on the service that computes the figures.
 */
class DashboardKpiScopingTest extends TestCase
{
    use BuildsMarketplace;
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function metrics(User $user): array
    {
        return app(DashboardMetrics::class)->forUser($user);
    }

    /** @return array<string, mixed> figures keyed by name for one lens */
    private function figures(array $metrics, string $lens): array
    {
        $lenses = collect($metrics['lenses']);
        $found = $lenses->firstWhere('key', $lens);
        $this->assertNotNull($found, "dashboard has no '{$lens}' lens");

        return collect($found['figures'])->pluck('value', 'key')->all();
    }

    private function world(): array
    {
        $subject = $this->makeSubject();
        $tutorA = $this->makeTutor(['hourly_rate' => 100], $subject, $this->makeUser('Tutor Alpha'));
        $tutorB = $this->makeTutor(['hourly_rate' => 40731], $subject, $this->makeUser('Tutor Bravo'));
        $studentA = $this->makeUser('Student Alpha');
        $studentB = $this->makeUser('Student Bravo');

        // Student A <-> Tutor A: one completed 2h (200), one upcoming pending.
        $this->makeSession($tutorA, $studentA, $subject, ['status' => 'completed', 'starts_at' => now()->subDays(3), 'duration_minutes' => 120]);
        $this->makeSession($tutorA, $studentA, $subject, ['status' => 'pending', 'starts_at' => now()->addDays(3)]);
        // Student B <-> Tutor B: completed 1h at 40731, and one more completed.
        $this->makeSession($tutorB, $studentB, $subject, ['status' => 'completed', 'starts_at' => now()->subDays(4)]);
        $this->makeSession($tutorB, $studentB, $subject, ['status' => 'completed', 'starts_at' => now()->subDays(5)]);

        return compact('tutorA', 'tutorB', 'studentA', 'studentB');
    }

    public function test_a_student_sees_only_their_own_sessions_and_spend(): void
    {
        ['studentA' => $studentA] = $this->world();

        $response = $this->metrics($studentA);
        $figures = $this->figures($response, 'student');

        $this->assertSame(2, $figures['sessions_total']);
        $this->assertSame(1, $figures['sessions_upcoming']);
        $this->assertSame(1, $figures['sessions_completed']);
        $this->assertEquals(200, $figures['value_completed']);
        $this->assertCount(1, $response['lenses']);
        $this->assertStringNotContainsString('40731', json_encode($response));
        $this->assertStringNotContainsString('Student Bravo', json_encode($response));
    }

    public function test_a_tutor_sees_their_own_sessions_and_earnings(): void
    {
        ['tutorA' => $tutorA] = $this->world();

        $response = $this->metrics($tutorA->user);
        $figures = $this->figures($response, 'tutor');

        $this->assertSame(2, $figures['sessions_total']);
        $this->assertSame(1, $figures['sessions_completed']);
        $this->assertEquals(200, $figures['value_completed']);
        $this->assertStringNotContainsString('40731', json_encode($response));
    }

    public function test_someone_who_is_both_tutor_and_student_gets_both_views(): void
    {
        ['tutorA' => $tutorA, 'tutorB' => $tutorB] = $this->world();
        $subject = $this->makeSubject('Physics');
        $this->makeSession($tutorB, $tutorA->user, $subject, ['status' => 'completed', 'starts_at' => now()->subDays(2)]);

        $response = $this->metrics($tutorA->user);

        $this->assertEquals(200, $this->figures($response, 'tutor')['value_completed']);
        $this->assertEquals(40731, $this->figures($response, 'student')['value_completed']);
    }

    public function test_an_operator_sees_platform_totals(): void
    {
        $this->world();
        $this->makeTutor(['status' => 'pending'], null, $this->makeUser('Waiting'));

        $response = $this->metrics($this->makeOperator());
        $figures = $this->figures($response, 'platform');

        $this->assertSame(4, $figures['sessions_total']);
        $this->assertSame(3, $figures['sessions_completed']);
        $this->assertSame(2, $figures['tutors_approved']);
        $this->assertSame(1, $figures['applications_pending']);
        $this->assertEquals(200 + 40731 + 40731, $figures['value_completed']);
    }

    public function test_a_brand_new_user_gets_zeroes_not_platform_figures(): void
    {
        $this->world();

        $response = $this->metrics($this->makeUser());
        $figures = $this->figures($response, 'student');

        $this->assertSame(0, $figures['sessions_total']);
        $this->assertEquals(0, $figures['value_completed']);
        $this->assertNull(collect($response['lenses'])->firstWhere('key', 'platform'));
    }

    public function test_the_dashboard_does_not_repeat_the_approved_tutor_count_query(): void
    {
        $this->world();
        $seen = [];
        DB::listen(function ($q) use (&$seen) {
            $seen[] = $q->sql.'|'.json_encode($q->bindings);
        });

        $this->metrics($this->makeOperator());

        $counts = array_filter($seen, fn ($q) => str_contains($q, 'count(*)') && str_contains($q, 'tutor_profiles'));
        $this->assertSame(count($counts), count(array_unique($counts)), 'a tutor_profiles count ran more than once');
    }

    public function test_the_older_dashboard_redirects_each_person_to_their_role_home(): void
    {
        ['studentA' => $student, 'tutorA' => $tutor] = $this->world();

        $this->actingAs($student)->get('/dashboard')->assertRedirect(route('learn.home', absolute: false));
        $this->actingAs($tutor->user)->get('/dashboard')->assertRedirect(route('teach.home', absolute: false));
        $this->actingAs($this->makeOperator())->get('/dashboard')->assertRedirect(route('ops.home', absolute: false));
    }
}
