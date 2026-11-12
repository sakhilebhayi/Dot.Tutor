<?php

namespace Tests\Feature\Perf;

use App\Domain\Learning\Enums\CourseStatus;
use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Services\CoursePublisher;
use App\Domain\Learning\Services\ProgressService;
use App\Domain\Marketplace\Services\BookingFlow;
use App\Domain\Marketplace\Services\Idempotency;
use App\Domain\Money\Models\Order;
use App\Domain\Money\Models\Payment;
use App\Domain\Money\Services\Orders;
use App\Domain\Perf\TutorInvariants;
use App\Models\TutorSession;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsMoney;
use Tests\Concerns\BuildsOfferings;
use Tests\TestCase;
use Throwable;

/**
 * Races run by separate PHP processes at the same instant (as separate PHP-FPM workers would serve them), each with
 * its own database connection: a SQLite file in WAL mode with IMMEDIATE transactions, or the PostgreSQL test database
 * under bin/test-pgsql.sh (row locks). After every race TutorInvariants must hold (R-47 D-1609, docs/R47_PERF.md
 * section 5): no double-booked tutor time or seat, no duplicate enrolment or order, one payment, a balanced ledger.
 */
class ConcurrencyInvariantsTest extends TestCase
{
    use BuildsMoney, BuildsOfferings {
        BuildsMoney::makeUser insteadof BuildsOfferings;
        BuildsMoney::makeSubject insteadof BuildsOfferings;
        BuildsMoney::makeCompany insteadof BuildsOfferings;
        BuildsMoney::makeTutorIn insteadof BuildsOfferings;
        BuildsMoney::stampSession insteadof BuildsOfferings;
        BuildsMoney::makeOperator insteadof BuildsOfferings;
        BuildsMoney::makeTutor insteadof BuildsOfferings;
        BuildsMoney::openWeek insteadof BuildsOfferings;
        BuildsMoney::slotAt insteadof BuildsOfferings;
        BuildsMoney::makeSession insteadof BuildsOfferings;
        BuildsMoney::bookingPayload insteadof BuildsOfferings;
    }

    private string $path;

    private string $db;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl/posix are needed to run contenders in separate processes.');
        }

        $this->path = sys_get_temp_dir().'/tutor-race-'.getmypid().'-'.bin2hex(random_bytes(4)).'.sqlite';
        $this->db = (string) config('database.default');

        if ($this->db === 'sqlite') {
            touch($this->path);
            config([
                'database.connections.sqlite.database' => $this->path,
                'database.connections.sqlite.busy_timeout' => 10000,
                'database.connections.sqlite.journal_mode' => 'wal',
                'database.connections.sqlite.transaction_mode' => 'IMMEDIATE',
            ]);
        }

        DB::purge($this->db);
        $this->artisan('migrate:fresh')->assertExitCode(0);
        config(['queue.default' => 'sync']);
    }

    protected function tearDown(): void
    {
        if (($this->db ?? 'sqlite') !== 'sqlite') {
            DB::purge($this->db);
            $this->artisan('migrate:fresh')->assertExitCode(0);
        }
        DB::disconnect($this->db ?? 'sqlite');
        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink(($this->path ?? '/nonexistent').$suffix);
        }

        parent::tearDown();
    }

    /**
     * @param  list<Closure(): mixed>  $contenders
     * @return list<array{ok: bool, value?: mixed, error?: string}>
     */
    private function race(array $contenders): array
    {
        DB::disconnect($this->db);
        $start = microtime(true) + 0.3;
        $pids = [];

        foreach ($contenders as $i => $contender) {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid, 'fork failed');

            if ($pid === 0) {
                DB::purge($this->db);
                while (microtime(true) < $start) {
                    usleep(100);
                }
                try {
                    $value = $contender();
                    $result = ['ok' => true, 'value' => is_object($value) && isset($value->id) ? $value->id : (is_array($value) && isset($value[0]->id) ? $value[0]->id : null)];
                } catch (Throwable $e) {
                    $result = ['ok' => false, 'error' => get_class($e).': '.$e->getMessage()];
                }
                file_put_contents("{$this->path}.result.{$i}", serialize($result));
                posix_kill(getmypid(), SIGKILL);
            }

            $pids[$i] = $pid;
        }

        $results = [];
        foreach ($pids as $i => $pid) {
            pcntl_waitpid($pid, $status);
            $results[$i] = unserialize((string) file_get_contents("{$this->path}.result.{$i}"));
            @unlink("{$this->path}.result.{$i}");
        }
        DB::purge($this->db);

        return $results;
    }

    private function assertInvariants(): void
    {
        $check = TutorInvariants::check();
        $this->assertTrue($check['ok'], 'invariants: '.json_encode($check));
    }

    public function test_four_learners_asking_for_the_same_slot_at_once_get_one_lesson(): void
    {
        [$tutor, $subject, $offering] = $this->bookableTutor();
        $learners = array_map(fn ($i) => $this->makeUser("Learner {$i}"), range(1, 4));
        $start = $this->slotAt($tutor, 2)->format('Y-m-d\TH:i:s\Z');

        $results = $this->race(array_map(fn (User $learner) => fn () => app(BookingFlow::class)->requestBooking($learner, $offering->fresh(), ['subject_id' => $subject->id, 'starts_at' => $start]), $learners));

        $this->assertSame(1, count(array_filter($results, fn ($r) => $r['ok'])), 'one request wins: '.json_encode($results));
        $this->assertSame(1, TutorSession::query()->where('tutor_profile_id', $tutor->id)->count());
        $this->assertInvariants();
    }

    public function test_a_double_tap_with_one_idempotency_key_is_one_lesson(): void
    {
        [$tutor, $subject, $offering] = $this->bookableTutor();
        $learner = $this->makeUser('Learner');
        $data = ['subject_id' => $subject->id, 'starts_at' => $this->slotAt($tutor, 2)->format('Y-m-d\TH:i:s\Z')];
        $tap = fn () => app(Idempotency::class)->run($learner, 'booking:'.$offering->id, 'tap-1', $data,
            fn () => app(BookingFlow::class)->requestBooking($learner, $offering->fresh(), $data)[0],
            fn (int $id) => TutorSession::query()->find($id));

        $results = $this->race([$tap, $tap, $tap]);

        $won = array_filter($results, fn ($r) => $r['ok']);
        $this->assertNotEmpty($won, json_encode($results));
        $this->assertCount(1, array_unique(array_column($won, 'value')), 'every successful tap answers the same lesson');
        $this->assertSame(1, TutorSession::query()->where('student_id', $learner->id)->count());
        $this->assertInvariants();
    }

    public function test_three_learners_racing_for_the_last_group_seat_never_overfill_it(): void
    {
        [$tutor, $subject, $offering] = $this->bookableTutor(['type' => 'group', 'title' => 'Revision group', 'capacity' => 2, 'price_minor' => 10000]);
        $group = app(BookingFlow::class)->scheduleGroup($tutor->user, $offering, ['subject_id' => $subject->id, 'starts_at' => $this->slotAt($tutor, 3)->toIso8601String()]);
        app(BookingFlow::class)->requestSeat($this->makeUser('First'), $group->fresh());
        $learners = array_map(fn ($i) => $this->makeUser("Racer {$i}"), range(1, 3));

        $results = $this->race(array_map(fn (User $learner) => fn () => app(BookingFlow::class)->requestSeat($learner, TutorSession::query()->findOrFail($group->id)), $learners));

        $this->assertSame(1, count(array_filter($results, fn ($r) => $r['ok'])), 'one seat was left: '.json_encode($results));
        $this->assertInvariants();
    }

    public function test_enrolling_four_times_at_once_is_one_enrolment(): void
    {
        $subject = $this->makeSubject('Mathematics');
        $owner = $this->makeTutor([], $subject)->user;
        $course = Course::query()->forceCreate(['owner_id' => $owner->id, 'slug' => 'race-course', 'title' => 'Race course', 'description' => 'A short course used to race enrolments against each other.', 'original_locale' => 'en', 'subject_id' => $subject->id,
            'objectives' => ['Learn'], 'prerequisites' => [], 'workload_minutes' => 30, 'pricing' => 'free', 'currency' => 'ZAR', 'completion_rule' => 'all_lessons', 'status' => CourseStatus::Draft]);
        $section = $course->sections()->create(['title' => 'One', 'position' => 1]);
        $section->lessons()->create(['course_id' => $course->id, 'title' => 'First', 'type' => 'text', 'body' => 'Text.', 'position' => 1]);
        app(CoursePublisher::class)->publish($course->fresh(), $owner);
        $learner = $this->makeUser('Learner');

        $enrol = fn () => app(ProgressService::class)->enrol($learner, Course::query()->findOrFail($course->id));
        $results = $this->race([$enrol, $enrol, $enrol, $enrol]);

        foreach ($results as $r) {
            $this->assertTrue($r['ok'], 'every attempt answers with the enrolment: '.($r['error'] ?? ''));
        }
        $this->assertSame(1, DB::table('learning_enrolments')->where('course_id', $course->id)->where('user_id', $learner->id)->count());
        $this->assertInvariants();
    }

    public function test_one_checkout_sent_four_times_with_one_key_is_one_order(): void
    {
        [$session, $learner] = $this->lesson();
        $create = fn () => app(Orders::class)->createOrder($learner, [$this->bookingItem($session)], 'race-key-1');

        $results = $this->race([$create, $create, $create, $create]);

        $ok = array_filter($results, fn ($r) => $r['ok']);
        $this->assertNotEmpty($ok, json_encode($results));
        $this->assertCount(1, array_unique(array_column($ok, 'value')), 'every successful call answers the same order');
        $this->assertSame(1, Order::query()->where('buyer_id', $learner->id)->count());
        $this->assertInvariants();
    }

    public function test_four_identical_paystack_webhooks_at_once_pay_the_order_once(): void
    {
        $this->setUpPaystack();
        [$session, $learner] = $this->lesson();
        $uuid = $this->placeOrder($learner, [$this->bookingItem($session)])->assertCreated()->json('order.id');
        $this->actingAs($learner)->postJson("/api/tutor/money/orders/{$uuid}/pay")->assertOk();
        $payment = Payment::query()->firstOrFail();
        $payload = ['event' => 'charge.success', 'data' => ['id' => 555, 'reference' => $payment->reference, 'status' => 'success', 'amount' => $payment->amount_minor, 'currency' => 'ZAR']];

        $deliver = fn () => $this->signedWebhook($payload)->assertOk()->json('status');
        $results = $this->race([$deliver, $deliver, $deliver, $deliver]);

        foreach ($results as $r) {
            $this->assertTrue($r['ok'], 'every delivery is acknowledged: '.($r['error'] ?? ''));
        }
        $this->assertSame(Order::PAID, Order::query()->where('uuid', $uuid)->value('status'));
        $this->assertSame(1, DB::table('money_webhook_events')->count(), 'one stored event');
        $this->assertSame(1, DB::table('money_ledger_journals')->where('kind', 'order_paid')->count(), 'one journal');
        $this->assertInvariants();
        $this->assertLedgerHealthy();
    }
}
