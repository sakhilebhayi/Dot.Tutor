<?php

namespace Tests\Feature\Analytics;

use App\Jobs\SendAnalyticsSnapshot;
use App\Services\Analytics\MetricsCollector;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsMarketplace;
use Tests\TestCase;

class PublishMetricsCommandTest extends TestCase
{
    use BuildsMarketplace;
    use RefreshDatabase;

    private const URL = 'https://analytics.test/api/ingest/snapshots';

    private const SECRET = 'analytics-ingest-secret-for-tests-0002';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 03:00:00');
        Cache::flush();
        config(['tutor.analytics' => ['url' => self::URL, 'secret' => self::SECRET, 'platform' => 'tutor', 'timeout' => 5, 'breaker_threshold' => 5, 'breaker_cooldown' => 300]]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_it_queues_one_job_per_company_and_skips_personal_and_unlinked_teams(): void
    {
        Queue::fake();
        $a = $this->makeCompany();
        $b = $this->makeCompany();
        $unlinked = $this->makeCompany();
        $unlinked->forceFill(['infodot_team_uuid' => null])->save();
        $personal = $this->makeUser('Solo')->currentTeam;
        $personal->forceFill(['infodot_team_uuid' => '11111111-2222-4333-8444-555555555555'])->save();

        $this->artisan('tutor:publish-metrics')->assertSuccessful();

        Queue::assertPushed(SendAnalyticsSnapshot::class, 2);
        $uuids = collect(Queue::pushed(SendAnalyticsSnapshot::class))->map(fn ($job) => $job->payload['company_uuid'])->sort()->values()->all();
        $this->assertSame(collect([$a->infodot_team_uuid, $b->infodot_team_uuid])->sort()->values()->all(), $uuids);
    }

    public function test_the_numbers_in_each_payload_belong_to_that_company_only(): void
    {
        Http::fake([self::URL => Http::response([], 201)]);
        $a = $this->makeCompany();
        $b = $this->makeCompany();
        $pa = $this->makeTutorIn($a);
        $pb = $this->makeTutorIn($b);
        $student = $this->makeUser('Student');
        $this->makeSession($pa, $student, $pa->subjects()->first());
        foreach (range(1, 3) as $i) {
            $this->makeSession($pb, $student, $pb->subjects()->first(), ['starts_at' => now()->addDays(10 + $i)]);
        }

        $this->artisan('tutor:publish-metrics')->assertSuccessful();

        $sent = collect(Http::recorded())->map(fn ($pair) => json_decode($pair[0]->body(), true))->keyBy('company_uuid');
        $this->assertCount(2, $sent);
        $this->assertSame(1, $sent[$a->infodot_team_uuid]['metrics']['sessions_7d']);
        $this->assertSame(3, $sent[$b->infodot_team_uuid]['metrics']['sessions_7d']);
        $this->assertSame('2026-11-05T00:00:00Z', $sent[$a->infodot_team_uuid]['taken_at']);
    }

    public function test_a_second_run_the_same_utc_day_carries_the_same_taken_at_but_the_next_day_a_new_one(): void
    {
        Http::fake([self::URL => Http::response([], 201)]);
        $this->makeCompany();

        $this->artisan('tutor:publish-metrics')->assertSuccessful();
        Carbon::setTestNow('2026-11-05 22:15:00');
        $this->artisan('tutor:publish-metrics')->assertSuccessful();
        Carbon::setTestNow('2026-11-06 03:00:00');
        $this->artisan('tutor:publish-metrics')->assertSuccessful();

        $stamps = collect(Http::recorded())->map(fn ($pair) => json_decode($pair[0]->body(), true)['taken_at'])->all();
        $this->assertSame(['2026-11-05T00:00:00Z', '2026-11-05T00:00:00Z', '2026-11-06T00:00:00Z'], $stamps);
    }

    public function test_blank_config_sends_nothing_queues_nothing_and_succeeds(): void
    {
        Http::fake();
        Queue::fake();
        $this->makeCompany();

        foreach ([['url' => ''], ['secret' => '']] as $blank) {
            config(['tutor.analytics' => array_merge(config('tutor.analytics'), $blank)]);

            $this->artisan('tutor:publish-metrics')->assertSuccessful()->expectsOutputToContain('disabled');
            config(['tutor.analytics' => array_merge(config('tutor.analytics'), ['url' => self::URL, 'secret' => self::SECRET])]);
        }

        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_company_option_publishes_for_that_company_only(): void
    {
        Queue::fake();
        $a = $this->makeCompany();
        $this->makeCompany();

        $this->artisan('tutor:publish-metrics', ['--company' => $a->infodot_team_uuid])->assertSuccessful();

        Queue::assertPushed(SendAnalyticsSnapshot::class, 1);
        $this->assertSame($a->infodot_team_uuid, Queue::pushed(SendAnalyticsSnapshot::class)->first()->payload['company_uuid']);
    }

    public function test_an_unknown_company_is_an_error_not_a_silent_success(): void
    {
        Queue::fake();
        $personal = $this->makeUser('Solo')->currentTeam;
        $personal->forceFill(['infodot_team_uuid' => '11111111-2222-4333-8444-555555555555'])->save();

        $this->artisan('tutor:publish-metrics', ['--company' => 'not-a-company'])->assertFailed();
        $this->artisan('tutor:publish-metrics', ['--company' => $personal->infodot_team_uuid])->assertFailed();

        Queue::assertNothingPushed();
    }

    public function test_dry_run_prints_the_payloads_and_sends_and_queues_nothing_even_when_enabled(): void
    {
        Http::fake();
        Queue::fake();
        $team = $this->makeCompany();
        $profile = $this->makeTutorIn($team);
        $this->makeSession($profile, $this->makeUser('Student'), $profile->subjects()->first());

        $this->assertSame(0, Artisan::call('tutor:publish-metrics', ['--dry-run' => true]));
        $output = Artisan::output();

        $this->assertStringContainsString($team->infodot_team_uuid, $output);
        $this->assertStringContainsString('"sessions_7d": 1', $output);
        $this->assertStringNotContainsString(self::SECRET, $output);

        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_dry_run_works_when_publishing_is_disabled(): void
    {
        Http::fake();
        config(['tutor.analytics' => array_merge(config('tutor.analytics'), ['secret' => ''])]);
        $team = $this->makeCompany();

        $this->artisan('tutor:publish-metrics', ['--dry-run' => true])->assertSuccessful()->expectsOutputToContain($team->infodot_team_uuid);

        Http::assertNothingSent();
    }

    public function test_one_company_failing_to_collect_does_not_stop_the_others(): void
    {
        Queue::fake();
        $good = $this->makeCompany();
        $bad = $this->makeCompany();
        $real = app(MetricsCollector::class);
        $this->mock(MetricsCollector::class, function ($mock) use ($real, $bad) {
            $mock->shouldReceive('companies')->andReturnUsing(fn (?string $uuid = null) => $real->companies($uuid));
            $mock->shouldReceive('collect')->andReturnUsing(function ($team) use ($real, $bad) {
                if ($team->id === $bad->id) {
                    throw new \RuntimeException('bad data');
                }

                return $real->collect($team);
            });
        });

        $this->artisan('tutor:publish-metrics')->assertSuccessful();

        Queue::assertPushed(SendAnalyticsSnapshot::class, 1);
        $this->assertSame($good->infodot_team_uuid, Queue::pushed(SendAnalyticsSnapshot::class)->first()->payload['company_uuid']);
    }

    public function test_an_analytics_outage_does_not_fail_the_command_or_touch_tutor_data(): void
    {
        Http::fake(['*' => Http::response('down', 500)]);
        $team = $this->makeCompany();
        $profile = $this->makeTutorIn($team);
        $session = $this->makeSession($profile, $this->makeUser('Student'), $profile->subjects()->first());

        $this->artisan('tutor:publish-metrics')->assertSuccessful();

        $this->assertSame('pending', $session->fresh()->status);
    }

    public function test_it_is_scheduled_daily_in_utc(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'tutor:publish-metrics'));

        $this->assertNotNull($event, 'tutor:publish-metrics must be scheduled');
        $this->assertSame('0 3 * * *', $event->expression);
        $this->assertSame('UTC', (string) $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
    }

    public function test_the_example_env_documents_the_settings_and_leaves_the_secret_blank(): void
    {
        $env = file_get_contents(base_path('.env.example'));

        $this->assertMatchesRegularExpression('/^ANALYTICS_INGEST_URL=$/m', $env);
        $this->assertMatchesRegularExpression('/^ANALYTICS_INGEST_SECRET=$/m', $env);
        $this->assertMatchesRegularExpression('/^ANALYTICS_PLATFORM_KEY=tutor$/m', $env);
    }
}
