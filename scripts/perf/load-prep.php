<?php

/*
 * R-47 load run preparation (DEVELOPMENT ONLY; docs/R47_PERF.md section 3). After world.php setup:
 *
 *   php scripts/perf/load-prep.php <classrooms> <bookers> > storage/perf/load.json
 *
 * Creates <classrooms> live one-to-one lessons (adult perf learners with perf tutors, starting five minutes ago) and
 * picks <bookers> adult learners who will all try to book the same few open slots of ten tutors at once. Mints a
 * signed-in database session for every person (as a browser has after signing in) and prints cookies, CSRF tokens
 * and URLs as JSON for scripts/perf/load.mjs. Same guards as world.php.
 */

use App\Models\TutorProfile;
use App\Models\User;
use App\Services\Scheduling\SlotFinder;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$connection = config('database.default');
$database = (string) config("database.connections.{$connection}.database");
if (! app()->environment('local', 'testing') || ! (($connection === 'sqlite' && str_contains(basename($database), 'perf')) || ($connection === 'pgsql' && str_starts_with($database, 'tutor_perf_')))) {
    fwrite(STDERR, "Refusing: development perf databases only.\n");
    exit(2);
}

$classrooms = (int) ($argv[1] ?? 50);
$bookers = (int) ($argv[2] ?? 100);

function mint(User $user): array
{
    $id = Str::random(40);
    $token = Str::random(40);
    DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'perf-load', 'last_activity' => now()->getTimestamp(),
        'payload' => base64_encode(json_encode(['_token' => $token, 'login_web_'.sha1(SessionGuard::class) => $user->id, '_flash' => ['old' => [], 'new' => []]]))]);
    $name = config('session.cookie');

    return ['cookie' => $name.'='.urlencode(app('encrypter')->encrypt(CookieValuePrefix::create($name, app('encrypter')->getKey()).$id, false)), 'csrf' => $token, 'user' => $user->id];
}

$higher = DB::table('curricula')->where('code', 'za-higher-education')->value('id');
$adults = User::query()->whereIn('id', DB::table('learner_profiles')->where('curriculum_id', $higher)->select('user_id'))
    ->where('email', 'like', 'perf.learner%')->orderBy('id')->limit($classrooms + $bookers)->get();
$tutors = TutorProfile::query()->whereHas('user', fn ($q) => $q->where('email', 'like', 'perf.tutor%'))->orderBy('id')->limit(max($classrooms, 10))->get();
$maths = DB::table('subjects')->where('slug', 'mathematics')->value('id');

$out = ['classrooms' => [], 'bookers' => [], 'targets' => [], 'room' => DB::table('community_rooms')->where('kind', 'subject')->where('audience', 'adult')->orderBy('id')->value('id')];

// Lessons running now: each tutor's other lessons from an hour before to an hour after are cancelled first, so the
// load lessons never double-book anyone (the invariants judge the run, not the set-up).
foreach ($adults->take($classrooms)->values() as $i => $learner) {
    $tutor = $tutors[$i % $tutors->count()];
    DB::table('tutor_sessions')->where('tutor_profile_id', $tutor->id)->whereBetween('starts_at', [now()->subHours(2)->toDateTimeString(), now()->addHours(2)->toDateTimeString()])
        ->update(['status' => 'cancelled', 'decision_reason' => 'Perf load: moved aside for a live classroom']);
    $lessonId = DB::table('tutor_sessions')->insertGetId([
        'tutor_profile_id' => $tutor->id, 'student_id' => $learner->id, 'subject_id' => $maths, 'status' => 'confirmed', 'delivery' => 'online',
        'starts_at' => now()->subMinutes(5)->addSeconds($i)->toDateTimeString(), 'duration_minutes' => 60, 'rate' => 250, 'rate_minor' => 25000, 'price_minor' => 25000,
        'currency' => 'ZAR', 'kind' => 'one_to_one', 'payment_status' => 'paid', 'notes' => 'Perf load classroom', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $out['classrooms'][] = ['lesson' => $lessonId, 'learner' => mint($learner), 'tutor' => mint($tutor->user)];
}

foreach ($tutors->take(10) as $tutor) {
    $offering = DB::table('tutor_offerings')->where('tutor_profile_id', $tutor->id)->where('type', 'one_to_one')->value('id');
    $slots = collect(app(SlotFinder::class)->forTutor($tutor->fresh(), 60))->flatMap(fn ($d) => $d['slots'])->slice(5, 3)->values();
    foreach ($slots as $slot) {
        $out['targets'][] = ['offering' => $offering, 'subject' => $maths, 'starts_at' => $slot->utc()->format('Y-m-d\TH:i:s\Z')];
    }
}

foreach ($adults->slice($classrooms)->values() as $learner) {
    $out['bookers'][] = mint($learner);
}

echo json_encode($out, JSON_PRETTY_PRINT).PHP_EOL;
