<?php

/*
 * R-47 page cost at volume (DEVELOPMENT ONLY; docs/R47_PERF.md section 2, D-1601).
 *
 *   php scripts/perf/pages.php [--filter regex] [--runs 7] [--json out.json] [--dups]
 *
 * Runs after `scripts/perf/world.php setup` against the same perf database. Every page goes through the full HTTP
 * stack in this process (database sessions minted into the sessions table, every middleware, Blade rendering), as
 * the person who would open it. For each page: median wall time, SQL statements, writes, time in the database,
 * response size, and the most repeated statement shape (the N+1 signal: the same SQL with different bindings).
 *
 * Same guards as world.php (APP_ENV local/testing and a *perf* SQLite file or a tutor_perf_* PostgreSQL database).
 */

use App\Domain\Academic\Models\AcademicLevel;
use App\Domain\Learning\Models\Course;
use App\Models\TutorProfile;
use App\Models\TutorSession;
use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

$connection = config('database.default');
$database = (string) config("database.connections.{$connection}.database");
if (! app()->environment('local', 'testing') || ! (($connection === 'sqlite' && str_contains(basename($database), 'perf')) || ($connection === 'pgsql' && str_starts_with($database, 'tutor_perf_')))) {
    fwrite(STDERR, "Refusing: development perf databases only (see the header).\n");
    exit(2);
}

$opt = fn (string $name, $default = null) => ($i = array_search("--{$name}", $argv, true)) !== false ? ($argv[$i + 1] ?? true) : $default;
$filter = $opt('filter');
$runs = (int) $opt('runs', 7);
$showDups = in_array('--dups', $argv, true);

$stats = ['q' => 0, 'w' => 0, 'db' => 0.0, 'shapes' => []];
DB::listen(function (QueryExecuted $q) use (&$stats): void {
    $stats['q']++;
    $stats['db'] += $q->time;
    if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $q->sql)) {
        $stats['w']++;
    }
    $shape = preg_replace(['/\s+/', '/\(\s*(\?\s*,\s*)+\?\s*\)/', '/\d+/'], [' ', '(?..)', 'N'], $q->sql);
    $stats['shapes'][$shape] = ($stats['shapes'][$shape] ?? 0) + 1;
    $stats['distinct'][$shape][md5(json_encode($q->bindings))] = true;
    $stats['all'][] = mb_strimwidth(preg_replace('/\s+/', ' ', $q->sql), 0, 170);
    if (! empty($GLOBALS['explainLog']) && preg_match('/^\s*select\b/i', $q->sql)) {
        $GLOBALS['explainLog'][$shape] ??= [$q->sql, $q->bindings];
    }
});

$http = app(HttpKernel::class);
$cookies = [];

function sessionCookie(User $user): string
{
    $id = Str::random(40);
    $GLOBALS['tokens'][$user->id] = Str::random(40);
    DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'perf-pages', 'last_activity' => now()->getTimestamp(),
        'payload' => base64_encode(json_encode(['_token' => $GLOBALS['tokens'][$user->id], 'login_web_'.sha1(SessionGuard::class) => $user->id, '_flash' => ['old' => [], 'new' => []]]))]);
    $name = config('session.cookie');

    return app('encrypter')->encrypt(CookieValuePrefix::create($name, app('encrypter')->getKey()).$id, false);
}

function hit(string $uri, ?User $as, array $headers = [], string $method = 'GET', ?array $body = null): array
{
    global $http, $cookies;
    $server = ['HTTP_ACCEPT' => $headers['Accept'] ?? 'text/html', 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'perf-pages'];
    foreach ($headers as $k => $v) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
    }
    $cookie = $as ? [config('session.cookie') => $cookies[$as->id] ??= sessionCookie($as)] : [];
    if ($method !== 'GET') {
        $server += ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $GLOBALS['tokens'][$as?->id] ?? '', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'];
    }
    $request = Request::create($uri, $method, [], $cookie, [], $server, $body === null ? null : json_encode($body));
    Auth::forgetGuards();
    Auth::setDefaultDriver('web'); // auth:sanctum switches the default for the request; a fresh worker starts at web
    // A fresh worker has a fresh session store: Store::start() merges into attributes left by the previous request.
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');
    $response = $http->handle($request);
    $http->terminate($request, $response);
    Auth::forgetGuards();
    app()->forgetInstance('request');

    return [$response->getStatusCode(), strlen((string) $response->getContent()), $response];
}

$person = fn (string $email) => User::query()->where('email', $email)->firstOrFail();
$matric = $person('demo.matric@example.test');
$adult = $person('demo.adult@example.test');
$tutor = $person('demo.workspace.tutor@example.test');
$ops = $person('demo.ops.admin@example.test');
$guardian = $person('demo.ops.guardian@example.test');
$young = $person('demo.grade3@example.test');
$caps = DB::table('curricula')->where('code', 'za-caps')->value('id');
$g12 = AcademicLevel::query()->where('curriculum_id', $caps)->where('code', 'grade-12')->value('id');
$maths = DB::table('subjects')->where('slug', 'mathematics')->value('id');
$course = Course::query()->where('slug', 'perf-matric-maths')->firstOrFail();
$lesson = DB::table('learning_lessons')->where('course_id', $course->id)->orderBy('id')->skip(150)->value('id');
$room = DB::table('community_rooms')->where('kind', 'subject')->where('audience', 'adult')->orderBy('id')->value('id');
$question = DB::table('community_posts')->where('room_id', $room)->where('kind', 'question')->orderByDesc('id')->value('id');
$workspace = TutorProfile::query()->where('user_id', $tutor->id)->firstOrFail();
$busyTutor = TutorProfile::query()->where('status', 'approved')->orderByDesc('total_sessions')->where('id', '!=', $workspace->id)->firstOrFail();
$myLesson = TutorSession::query()->where('student_id', $matric->id)->orderByDesc('starts_at')->value('id');
$order = DB::table('money_orders')->orderByDesc('id')->value('uuid');
$child = $person('demo.ops.child@example.test');
$classLearner = $person('demo.classroom.learner@example.test');
$classTutor = $person('demo.classroom.tutor@example.test');
$classLesson = TutorSession::query()->where('notes', 'Demo classroom lesson')->value('id');
TutorSession::query()->whereKey($classLesson)->update(['starts_at' => now()->addMinutes(5)->startOfMinute(), 'status' => 'confirmed']);

$pages = [
    ['learner', 'learner home', '/learn', $matric],
    ['learner', 'matching (Grade 12 Maths, isiZulu)', '/find-a-tutor?'.http_build_query(['subject_id' => $maths, 'level_id' => $g12, 'curriculum_id' => $caps, 'languages' => ['zu', 'en']]), $matric],
    ['learner', 'matching (subject only)', '/find-a-tutor?subject_id='.$maths, $matric],
    ['learner', 'tutor storefront (busiest tutor)', '/t/'.$busyTutor->id, $matric],
    ['learner', 'tutor storefront (workspace tutor)', '/t/'.$workspace->id, $matric],
    ['public', 'public storefront by handle (redirect)', '/u/demo-workspace-tutor', null],
    ['learner', 'course player (lesson 151 of 300)', "/learning/courses/{$course->id}/learn/{$lesson}", $matric],
    ['learner', 'course page', "/learning/courses/{$course->id}", $matric],
    ['learner', 'my courses', '/learn/courses', $matric],
    ['learner', 'progress', '/learn/progress', $matric],
    ['learner', 'my bookings', '/my/bookings', $matric],
    ['learner', 'one lesson', "/learn/lessons/{$myLesson}", $matric],
    ['learner', 'inbox (600 notices)', '/comms/inbox', $matric],
    ['learner', 'purchases', '/learn/purchases', $matric],
    ['learner', 'rooms index', '/rooms', $matric],
    ['young', 'young learner home', '/learn', $young],
    ['room', 'room page (6,000 posts)', "/rooms/{$room}", $adult],
    ['room', 'room question thread', "/rooms/posts/{$question}", $adult],
    ['tutor', 'tutor home', '/teach', $tutor],
    ['tutor', 'tutor bookings (2,400 lessons)', '/teach/bookings', $tutor],
    ['tutor', 'tutor calendar', '/teach/calendar', $tutor],
    ['tutor', 'tutor students', '/teach/students', $tutor],
    ['tutor', 'tutor earnings', '/teach/earnings', $tutor],
    ['tutor', 'tutor payouts', '/teach/payouts', $tutor],
    ['tutor', 'tutor insights', '/teach/insights', $tutor],
    ['tutor', 'tutor storefront editor', '/teach/storefront', $tutor],
    ['tutor', 'money earnings (domain page)', '/money/earnings', $tutor],
    ['tutor', 'tutor courses', '/teach/courses', $tutor],
    ['ops', 'ops overview', '/ops', $ops],
    ['ops', 'ops payments', '/ops/payments', $ops],
    ['ops', 'ops one order', "/ops/payments/orders/{$order}", $ops],
    ['ops', 'ops payouts', '/ops/payouts', $ops],
    ['ops', 'ops users', '/ops/users', $ops],
    ['ops', 'ops reviews', '/ops/reviews', $ops],
    ['ops', 'ops verification', '/ops/verification', $ops],
    ['ops', 'ops audit', '/ops/audit', $ops],
    ['ops', 'money ops (domain page)', '/money/ops', $ops],
    ['guardian', 'guardian home', '/guardian', $guardian],
    ['guardian', 'guardian child', "/guardian/children/{$child->id}", $guardian],
    ['poll', 'room activity (ETag match)', "/rooms/{$room}/activity", $adult, ['Accept' => 'application/json']],
    ['poll', 'inbox summary', '/comms/inbox/summary', $matric, ['Accept' => 'application/json']],
    ['poll', 'classroom poll (calm, nothing new)', "/lessons/{$classLesson}/classroom/api/poll", $classLearner, ['Accept' => 'application/json', 'X-Client-Id' => 'perf-'.$classLearner->id], 'POST',
        ['since' => PHP_INT_MAX, 'chat_since' => PHP_INT_MAX, 'board_since' => PHP_INT_MAX, 'notes_version' => 0, 'files_since' => 0, 'mic' => true, 'cam' => true, 'sharing' => false],
        function () use ($classLesson, $classLearner, $classTutor) {
            foreach ([$classTutor, $classLearner] as $who) {
                [$status, , $r] = hit("/lessons/{$classLesson}/classroom/api/join", $who, ['Accept' => 'application/json', 'X-Client-Id' => 'perf-'.$who->id], 'POST', ['client_id' => 'perf-'.$who->id]);
                if ($status !== 200) {
                    fwrite(STDERR, "classroom join {$status}: ".substr((string) $r->getContent(), 0, 200).PHP_EOL);
                }
            }
        }],
];

$results = [];
foreach ($pages as $page) {
    [$group, $label, $uri, $as] = $page;
    $headers = $page[4] ?? [];
    $method = $page[5] ?? 'GET';
    $body = $page[6] ?? null;
    if ($filter && ! preg_match('/'.$filter.'/i', "{$group} {$label} {$uri}")) {
        continue;
    }
    if (isset($page[7])) {
        ($page[7])(); // set-up (joining the classroom before its polls are measured)
    }
    hit($uri, $as, $headers, $method, $body); // warm: opcache, first-touch caches
    if (str_contains($uri, '/activity')) {
        // The poll's steady state: the client sends the ETag it was given.
        [, , $first] = hit($uri, $as, $headers);
        $headers['If-None-Match'] = (string) $first->headers->get('ETag');
    }
    $samples = [];
    for ($i = 0; $i < $runs; $i++) {
        $stats = ['q' => 0, 'w' => 0, 'db' => 0.0, 'shapes' => [], 'distinct' => [], 'all' => []];
        $t = hrtime(true);
        [$status, $bytes, $response] = hit($uri, $as, $headers, $method, $body);
        $location = $response->headers->get('Location');
        $ms = (hrtime(true) - $t) / 1e6;
        arsort($stats['shapes']);
        $samples[] = ['ms' => $ms, 'q' => $stats['q'], 'w' => $stats['w'], 'db' => $stats['db'], 'status' => $status, 'bytes' => $bytes, 'top' => array_slice($stats['shapes'], 0, 3, true), 'distinct' => array_map('count', $stats['distinct'])];
    }
    usort($samples, fn ($a, $b) => $a['ms'] <=> $b['ms']);
    $mid = $samples[intdiv(count($samples), 2)];
    $topShape = array_key_first($mid['top']);
    $row = ['group' => $group, 'page' => $label, 'uri' => $uri, 'status' => $mid['status'], 'ms' => round($mid['ms'], 1), 'queries' => $mid['q'], 'writes' => $mid['w'],
        'db_ms' => round($mid['db'], 1), 'kb' => round($mid['bytes'] / 1024, 1), 'max_repeat' => $topShape ? $mid['top'][$topShape] : 0, 'top' => $mid['top']];
    if ($status >= 300 && $status < 400) {
        $row['location'] = $location;
    }
    $results[] = $row;
    printf("%-9s %-38s %3d %8.1f ms %5d q %3d w %7.1f db %7.1f KB  repeat %4d\n", $group, mb_strimwidth($label, 0, 38), $row['status'], $row['ms'], $row['queries'], $row['writes'], $row['db_ms'], $row['kb'], $row['max_repeat']);
    if (in_array('--sql', $argv, true)) {
        foreach ($stats['all'] as $n => $sql) {
            printf("            %2d %s\n", $n + 1, $sql);
        }
    }
    if (isset($row['location'])) {
        printf("            -> %s\n", $row['location']);
    }
    if ($showDups && $row['max_repeat'] > 3) {
        foreach ($mid['top'] as $shape => $n) {
            if ($n > 3) {
                printf("            x%-4d (%d distinct) %s\n", $n, $mid['distinct'][$shape] ?? 0, mb_strimwidth($shape, 0, 200));
            }
        }
    }
}

// --explain: every distinct SELECT the pages ran, through the engine's planner; full scans of big tables are listed.
if (in_array('--explain', $argv, true)) {
    $big = [];
    foreach (DB::connection()->getSchemaBuilder()->getTableListing() as $table) {
        $table = str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;
        $n = DB::table($table)->count();
        if ($n >= 2000) {
            $big[$table] = $n;
        }
    }
    $GLOBALS['explainLog'] = ['__on' => null];
    foreach ($pages as $page) {
        [$group, $label, $uri, $as] = $page;
        if ($filter && ! preg_match('/'.$filter.'/i', "{$group} {$label} {$uri}")) {
            continue;
        }
        hit($uri, $as, $page[4] ?? []);
    }
    unset($GLOBALS['explainLog']['__on']);
    $flagged = 0;
    foreach ($GLOBALS['explainLog'] as $shape => [$sql, $bindings]) {
        $plan = $connection === 'sqlite'
            ? collect(DB::select('EXPLAIN QUERY PLAN '.$sql, $bindings))->pluck('detail')->implode(' | ')
            : collect(DB::select('EXPLAIN '.$sql, $bindings))->map(fn ($r) => array_values((array) $r)[0])->implode(' | ');
        foreach ($big as $table => $n) {
            $scan = $connection === 'sqlite'
                ? preg_match('/\bSCAN '.preg_quote($table).'\b(?! USING)/', $plan)
                : preg_match('/Seq Scan on '.preg_quote($table).'\b/', $plan);
            if ($scan) {
                $flagged++;
                printf("FULL SCAN %s (%d rows)\n    %s\n    plan: %s\n", $table, $n, mb_strimwidth($shape, 0, 260), mb_strimwidth($plan, 0, 300));
            }
        }
    }
    printf("%d distinct selects, %d full scans of tables with 2,000+ rows\n", count($GLOBALS['explainLog']), $flagged);
}

if ($out = $opt('json')) {
    file_put_contents($out, json_encode($results, JSON_PRETTY_PRINT));
}
