<?php

namespace Tests\Feature\Perf;

use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Requests on the production session stack (database sessions, a real session cookie, no actingAs shortcut) with
 * every SQL statement counted (R-47 perf, docs/R47_PERF.md). Budgets are operation counts, not wall clock, so they
 * hold on any machine. Run with TUTOR_PERF_DUMP=1 to print each measured request's statements.
 */
trait MeasuresRequests
{
    /** @var list<string> */
    private array $statements = [];

    private bool $listening = false;

    protected function useDatabaseSessions(): void
    {
        config(['session.driver' => 'database', 'session.touch_seconds' => 60]);
        $this->app['session']->forgetDrivers();
        $this->listenToQueries();
    }

    protected function listenToQueries(): void
    {
        if (! $this->listening) {
            DB::listen(fn (QueryExecuted $q) => $this->statements[] = $q->sql);
            $this->listening = true;
        }
    }

    /** @return array{0: string, 1: string} session id and CSRF token of a signed-in browser */
    protected function signedInSession(User $user): array
    {
        $id = Str::random(40);
        $token = Str::random(40);
        DB::table('sessions')->insert([
            'id' => $id, 'user_id' => $user->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'Symfony',
            'payload' => base64_encode(json_encode(['_token' => $token, 'login_web_'.sha1(SessionGuard::class) => $user->id, '_flash' => ['old' => [], 'new' => []]])),
            'last_activity' => now()->getTimestamp(),
        ]);

        return [$id, $token];
    }

    protected function asBrowser(array $session, array $headers = []): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withCredentials()->withCookie(config('session.cookie'), $session[0])
            ->withHeaders($headers + ['X-CSRF-TOKEN' => $session[1], 'X-Requested-With' => 'XMLHttpRequest']);
    }

    /** @return array{0: TestResponse, 1: int, 2: int} response, statements, writes */
    protected function measure(callable $request): array
    {
        $this->app['auth']->forgetGuards();
        $this->statements = [];
        $response = $request();
        $statements = $this->statements;
        if (getenv('TUTOR_PERF_DUMP')) {
            fwrite(STDERR, PHP_EOL.implode(PHP_EOL, $statements).PHP_EOL);
        }

        return [$response, count($statements), count(array_filter($statements, fn ($sql) => preg_match('/^\s*(insert|update|delete|replace)\b/i', $sql) === 1))];
    }

    /** Statements a callable runs, without HTTP. */
    protected function statementsOf(callable $work): int
    {
        $this->statements = [];
        $work();

        return count($this->statements);
    }
}
