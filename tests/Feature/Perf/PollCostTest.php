<?php

namespace Tests\Feature\Perf;

use App\Models\TutorSession;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Classroom\BuildsClassroom;
use Tests\TestCase;

/**
 * The polling endpoints' cost per request (R-47 D-1608, docs/R47_PERF.md section 3). A live lesson polls every 1 to
 * 2 seconds per person and a room every 30 seconds per open tab, so a statement or a write added here multiplies by
 * every person online. The rate limiter keeps the store it was built with in tests (array); its own cost on the
 * database store (5 statements, 1 write) is counted in the perf world runs instead.
 */
class PollCostTest extends TestCase
{
    use BuildsClassroom;
    use MeasuresRequests;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDatabaseSessions();
    }

    /** @return array{0: User, 1: TutorSession, 2: array} the learner, the lesson and the learner's browser session */
    private function learnerInLesson(): array
    {
        [$tutor, $profile] = $this->tutor();
        $learner = $this->adult('Learner Sipho');
        $lesson = $this->lesson($profile, $learner);
        $this->joinAs($tutor, $lesson, 'tt')->assertOk();
        $session = $this->signedInSession($learner);
        $this->asBrowser($session, ['X-Client-Id' => 'll'])->postJson(route('classroom.api.join', $lesson), ['client_id' => 'll'])->assertOk();

        return [$learner, $lesson, $session];
    }

    private function poll(array $session, TutorSession $lesson): TestResponse
    {
        return $this->asBrowser($session, ['X-Client-Id' => 'll'])->postJson(route('classroom.api.poll', $lesson), ['since' => 0, 'chat_since' => 0, 'board_since' => 0, 'files_since' => 0, 'notes_version' => 0]);
    }

    public function test_a_calm_classroom_poll_stays_within_its_statement_budget(): void
    {
        [, $lesson, $session] = $this->learnerInLesson();
        $this->poll($session, $lesson)->assertOk(); // warm: the admission check is cached for 30 s after this
        // The browser polls every 2 s. Without moving the clock, the attendee's last_seen_at sometimes lands in the
        // same second as the warm poll and Eloquent skips that update, so the write count flickered between 2 and 3.
        $this->travel(2)->seconds();

        [$response, $statements, $writes] = $this->measure(fn () => $this->poll($session, $lesson));

        $response->assertOk();
        // session 1, user 1, lesson 1, interface language 1, classroom 1, attendee 2, admission cache 1, attendee
        // touch 1, attendance touch 1, roster sweep 1, signal purge 1, roster 2, signals 1, chat 1, board 1, files 1.
        $this->assertLessThanOrEqual(20, $statements, 'statements in a calm classroom poll');
        $this->assertLessThanOrEqual(3, $writes, 'attendee touch, attendance touch and the expired-signal purge; the session row is not rewritten');
    }

    public function test_polls_rewrite_the_session_row_at_most_once_a_minute(): void
    {
        [, $lesson, $session] = $this->learnerInLesson();
        $this->poll($session, $lesson)->assertOk();
        $writes = 0;
        DB::listen(function (QueryExecuted $q) use (&$writes) {
            if (preg_match('/^(update|insert into) "sessions"/', $q->sql) === 1) {
                $writes++;
            }
        });

        for ($i = 0; $i < 30; $i++) { // a minute of polling every 2 s
            $this->travel(2)->seconds();
            $this->poll($session, $lesson)->assertOk();
        }

        $this->assertLessThanOrEqual(1, $writes, 'session writes during a minute of polling');
    }

    public function test_a_changed_session_is_still_written_at_once(): void
    {
        [, $lesson, $session] = $this->learnerInLesson();
        $this->poll($session, $lesson)->assertOk();
        $before = DB::table('sessions')->where('id', $session[0])->value('payload');

        // A page view records the previous URL in the session: that change is written.
        $this->asBrowser($session, ['X-Requested-With' => ''])->get(route('classroom.show', $lesson))->assertOk();

        $this->assertNotSame($before, DB::table('sessions')->where('id', $session[0])->value('payload'));
    }

    public function test_poll_answers_carry_no_cookie_or_policy_overhead_and_pages_keep_theirs(): void
    {
        [, $lesson, $session] = $this->learnerInLesson();
        $this->poll($session, $lesson)->assertOk();

        $response = $this->poll($session, $lesson)->assertOk();
        $names = array_map(fn ($c) => $c->getName(), $response->headers->getCookies());
        $this->assertNotContains('XSRF-TOKEN', $names);
        $this->assertNotContains(config('session.cookie'), $names, 'session unchanged and touched recently: cookie not re-sent');
        $this->assertFalse($response->headers->has('Content-Security-Policy-Report-Only'));
        $this->assertFalse($response->headers->has('Content-Security-Policy'));
        $this->assertTrue($response->headers->has('X-Content-Type-Options'), 'the other security headers stay');

        $page = $this->asBrowser($session, ['X-Requested-With' => ''])->get(route('classroom.show', $lesson))->assertOk();
        $this->assertContains('XSRF-TOKEN', array_map(fn ($c) => $c->getName(), $page->headers->getCookies()));
        $this->assertTrue($page->headers->has('Content-Security-Policy-Report-Only') || $page->headers->has('Content-Security-Policy'));
    }
}
