<?php

namespace Tests\Feature\Deploy;

use App\Console\Commands\TutorPreflight;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * tutor:preflight, the check deploy.yml runs before the site leaves maintenance mode: a FAIL keeps it down, so FAIL is
 * reserved for what breaks the site and every owner step is a WARN or OFF (R-47 D-1901).
 */
class TutorPreflightTest extends TestCase
{
    use RefreshDatabase;

    private ?string $publicCopy = null;

    protected function tearDown(): void
    {
        if ($this->publicCopy !== null) {
            File::deleteDirectory($this->publicCopy);
        }

        parent::tearDown();
    }

    /**
     * @return array{exit: int, ok: bool, counts: array<string, int>, rows: array<string, array{status: string, detail: string}>}
     */
    private function preflight(array $options = []): array
    {
        $exit = Artisan::call('tutor:preflight', ['--json' => true] + $options);
        $data = json_decode(Artisan::output(), true);
        $this->assertIsArray($data, 'preflight --json printed JSON');

        $rows = [];
        foreach ($data['results'] as $row) {
            $rows[$row['area'].'/'.$row['check']] = ['status' => $row['status'], 'detail' => $row['detail']];
        }

        return ['exit' => $exit, 'ok' => $data['ok'], 'counts' => $data['counts'], 'rows' => $rows];
    }

    /** A copy of public/ with a built manifest naming every @vite entry, so the frontend rows can pass without a build. */
    private function usePublicWithBuild(): void
    {
        $dir = sys_get_temp_dir().'/tutor-preflight-'.bin2hex(random_bytes(4));
        File::copyDirectory(public_path(), $dir);
        File::deleteDirectory($dir.'/build');
        File::ensureDirectoryExists($dir.'/build/assets');

        $manifest = [];
        foreach (TutorPreflight::viteEntriesInViews() as $i => $entry) {
            $file = "assets/entry-{$i}.js";
            File::put($dir.'/build/'.$file, '/* built */');
            $manifest[$entry] = ['file' => $file, 'isEntry' => true, 'src' => $entry];
        }
        File::put($dir.'/build/manifest.json', (string) json_encode($manifest));

        $this->publicCopy = $dir;
        $this->app->usePublicPath($dir);
    }

    private function bookableTutor(): void
    {
        $user = User::factory()->create();
        $profile = DB::table('tutor_profiles')->insertGetId([
            'user_id' => $user->id, 'hourly_rate' => 250, 'hourly_rate_minor' => 25000, 'status' => 'approved',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('tutor_offerings')->insert([
            'tutor_profile_id' => $profile, 'type' => 'one_to_one', 'title' => 'Grade 11 Mathematics', 'price_minor' => 25000,
            'currency' => 'ZAR', 'duration_minutes' => 60, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('tutor_availability_windows')->insert([
            'tutor_profile_id' => $profile, 'weekday' => 2, 'start_time' => '15:00', 'end_time' => '18:00', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_a_fresh_install_names_every_blocker_and_only_the_missing_build_fails(): void
    {
        $this->app->usePublicPath(sys_get_temp_dir().'/tutor-preflight-empty-'.bin2hex(random_bytes(3)));
        $result = $this->preflight();

        $this->assertSame(1, $result['exit']);
        $this->assertSame('FAIL', $result['rows']['Frontend/Built assets']['status']);
        $this->assertSame('PASS', $result['rows']['Database/Migrations']['status']);
        $this->assertSame('WARN', $result['rows']['Reference data/Academic structure']['status']);
        $this->assertStringContainsString('academic:sync', $result['rows']['Reference data/Academic structure']['detail']);

        $ready = $result['rows']['Readiness/Can a learner book a paid lesson?'];
        $this->assertSame('WARN', $ready['status']);
        $this->assertStringContainsString('no Paystack key', $ready['detail']);
        $this->assertStringContainsString('academic reference data', $ready['detail']);
        $this->assertStringContainsString('no approved tutor', $ready['detail']);
    }

    public function test_a_seeded_install_with_test_keys_can_book_a_paid_lesson(): void
    {
        $this->usePublicWithBuild();
        Artisan::call('academic:sync');
        $this->bookableTutor();
        config(['money.paystack.secret_key' => 'sk_test_preflight', 'app.url' => 'https://tutor.example.co.za']);

        $result = $this->preflight();

        $this->assertSame(0, $result['exit'], json_encode(array_filter($result['rows'], fn ($r) => $r['status'] === 'FAIL')));
        $this->assertSame(0, $result['counts']['FAIL']);
        $this->assertSame('PASS', $result['rows']['Reference data/Academic structure']['status']);
        $this->assertSame('INFO', $result['rows']['Providers/Paystack']['status'], 'test mode outside production is a fact, not a warning');
        $this->assertStringContainsString('/api/tutor/money/webhooks/paystack', $result['rows']['Providers/Paystack']['detail']);
        $this->assertSame('PASS', $result['rows']['People/Tutors']['status']);
        $this->assertSame('PASS', $result['rows']['Readiness/Can a learner book a paid lesson?']['status']);
    }

    public function test_paystack_modes_follow_the_second_switch(): void
    {
        config(['money.paystack.secret_key' => 'sk_live_preflight', 'money.paystack.allow_live' => false]);
        $refused = $this->preflight()['rows'];
        $this->assertSame('WARN', $refused['Providers/Paystack']['status']);
        $this->assertStringContainsString('PAYSTACK_ALLOW_LIVE', $refused['Providers/Paystack']['detail']);
        $this->assertStringContainsString('Paystack key is refused', $refused['Readiness/Can a learner book a paid lesson?']['detail']);

        config(['money.paystack.allow_live' => true]);
        $this->assertSame('PASS', $this->preflight()['rows']['Providers/Paystack']['status']);

        config(['money.paystack.secret_key' => 'sk_test_x', 'money.payouts.paystack_transfers' => true]);
        $this->assertSame('INFO', $this->preflight()['rows']['Providers/Payout transfers']['status']);
    }

    public function test_optional_providers_are_off_and_half_configured_ones_warn_never_fail(): void
    {
        $rows = $this->preflight()['rows'];
        $this->assertSame('OFF', $rows['Providers/AI assistant']['status']);
        $this->assertSame('OFF', $rows['Providers/Web Push']['status']);
        $this->assertSame('OFF', $rows['Providers/SMS']['status']);
        $this->assertSame('OFF', $rows['Providers/Dot.Notify']['status']);
        $this->assertSame('OFF', $rows['Providers/Dot.Analytics']['status']);

        config([
            'ai.enabled' => true, 'ai.anthropic.api_key' => '',
            'comms.push.public_key' => 'not-a-key',
            'comms.sms.driver' => 'bulksms',
            'notify.url' => 'https://notify.example.co.za/ingest',
            'classroom.rtc.turn_urls' => ['turn:turn.example.co.za:3478'],
        ]);
        $rows = $this->preflight()['rows'];
        foreach (['AI assistant', 'Web Push', 'SMS', 'Dot.Notify', 'Classroom relay (TURN)'] as $check) {
            $this->assertSame('WARN', $rows["Providers/{$check}"]['status'], $check);
        }

        config(['classroom.rtc.turn_secret' => 'shared-secret']);
        $this->assertSame('PASS', $this->preflight()['rows']['Providers/Classroom relay (TURN)']['status']);
    }

    public function test_ai_reports_its_model_budget_and_spend(): void
    {
        config(['ai.enabled' => true, 'ai.anthropic.api_key' => 'sk-ant-test', 'ai.limits.monthly_budget_micro_usd' => 1_000_000]);
        $user = User::factory()->create();
        DB::table('ai_interactions')->insert([
            'user_id' => $user->id, 'actor_role' => 'learner', 'task' => 'explain', 'status' => 'answered',
            'cost_micro_usd' => 1_200_000, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $row = $this->preflight()['rows']['Providers/AI assistant'];
        $this->assertSame('WARN', $row['status']);
        $this->assertStringContainsString('spent this month $1.20', $row['detail']);
        $this->assertStringContainsString('budget is used up', $row['detail']);
    }

    public function test_production_debug_fails_and_a_local_url_blocks_payment_without_failing(): void
    {
        $this->app['env'] = 'production';
        config(['app.debug' => true, 'app.url' => 'http://localhost']);

        $result = $this->preflight();
        $this->assertSame('FAIL', $result['rows']['Application/APP_DEBUG']['status']);
        $this->assertSame('WARN', $result['rows']['Application/APP_URL']['status']);
        $this->assertStringContainsString('APP_URL is a local address', $result['rows']['Readiness/Can a learner book a paid lesson?']['detail']);
        $this->assertSame(1, $result['exit']);
    }

    public function test_a_private_disk_under_public_or_with_a_url_fails(): void
    {
        $public = public_path();
        config(['filesystems.disks.safety.root' => $public.'/uploads']);
        $this->assertSame('FAIL', TutorPreflight::privateDiskRow('Safety', 'safety', $public)['status']);

        config(['filesystems.disks.learning.url' => 'https://tutor.example.co.za/files']);
        $this->assertSame('FAIL', TutorPreflight::privateDiskRow('Learning', 'learning', $public)['status']);

        config(['filesystems.disks.lesson-resources.driver' => 's3']);
        $this->assertSame('FAIL', TutorPreflight::privateDiskRow('Classroom', 'lesson-resources', $public)['status']);

        $this->assertSame('PASS', TutorPreflight::privateDiskRow('Marketplace', 'local', $public)['status']);
    }

    public function test_frontend_rows_fail_on_a_missing_entry_font_or_static_file(): void
    {
        $this->usePublicWithBuild();
        $rows = collect(TutorPreflight::frontendRows($this->publicCopy, resource_path()))->keyBy('check');
        $this->assertSame('PASS', $rows['Built assets']['status']);
        $this->assertSame('PASS', $rows['Self-hosted fonts']['status']);
        $this->assertSame('PASS', $rows['Icons and static files']['status']);

        $manifest = json_decode((string) File::get($this->publicCopy.'/build/manifest.json'), true);
        unset($manifest[array_key_first($manifest)]);
        File::put($this->publicCopy.'/build/manifest.json', (string) json_encode($manifest));
        File::delete($this->publicCopy.'/comms-sw.js');
        File::delete(File::glob($this->publicCopy.'/fonts/*.woff2')[0]);

        $rows = collect(TutorPreflight::frontendRows($this->publicCopy, resource_path()))->keyBy('check');
        $this->assertSame('FAIL', $rows['Built assets']['status']);
        $this->assertStringContainsString('Missing from the manifest', $rows['Built assets']['detail']);
        $this->assertSame('FAIL', $rows['Self-hosted fonts']['status']);
        $this->assertSame('FAIL', $rows['Icons and static files']['status']);
        $this->assertStringContainsString('comms-sw.js', $rows['Icons and static files']['detail']);
    }

    public function test_limits_are_judged_against_the_largest_upload_and_the_video_limit(): void
    {
        $rows = collect(TutorPreflight::evaluateLimits(['upload_max_filesize' => '2M', 'post_max_size' => '8M', 'memory_limit' => '128M', 'max_execution_time' => '30'], 20 * 1048576, 200 * 1048576))->keyBy('check');
        $this->assertSame('WARN', $rows['upload_max_filesize']['status']);
        $this->assertSame('WARN', $rows['post_max_size']['status']);
        $this->assertSame('WARN', $rows['memory_limit']['status']);

        $rows = collect(TutorPreflight::evaluateLimits(['upload_max_filesize' => '64M', 'post_max_size' => '70M', 'memory_limit' => '256M', 'max_execution_time' => '60'], 20 * 1048576, 200 * 1048576))->keyBy('check');
        $this->assertSame('INFO', $rows['upload_max_filesize']['status'], 'documents fit, videos do not');
        $this->assertSame('PASS', $rows['post_max_size']['status']);

        $this->assertSame('FAIL', collect(TutorPreflight::evaluateLimits(['memory_limit' => '32M']))->keyBy('check')['memory_limit']['status']);
        $this->assertSame(20 * 1048576, TutorPreflight::largestUploadBytes());
    }

    public function test_operators_and_safeguarding_contacts_are_counted(): void
    {
        $rows = $this->preflight()['rows'];
        $this->assertStringContainsString('ops:grant', $rows['People/Operators']['detail']);
        $this->assertStringContainsString('nobody', $rows['People/Safeguarding contacts']['detail']);

        User::factory()->create(['is_platform_operator' => true]);
        config(['mail.default' => 'smtp']);
        $rows = $this->preflight()['rows'];
        $this->assertSame('PASS', $rows['People/Operators']['status']);
        $this->assertStringContainsString('1 platform operator', $rows['People/Operators']['detail']);
    }

    public function test_every_scheduled_task_the_product_needs_is_registered(): void
    {
        $this->assertSame('PASS', $this->preflight()['rows']['Schedule/Registered tasks']['status']);
    }

    public function test_strict_turns_warnings_into_a_failing_exit(): void
    {
        $this->usePublicWithBuild();
        $result = $this->preflight();
        $this->assertSame(0, $result['counts']['FAIL']);
        $this->assertGreaterThan(0, $result['counts']['WARN']);
        $this->assertSame(0, $result['exit']);
        $this->assertSame(1, $this->preflight(['--strict' => true])['exit']);
    }

    public function test_the_text_report_ends_with_a_summary(): void
    {
        $this->usePublicWithBuild();
        $this->artisan('tutor:preflight')
            ->expectsOutputToContain('Can a learner book a paid lesson?')
            ->expectsOutputToContain('Preflight OK:')
            ->assertExitCode(0);
    }
}
