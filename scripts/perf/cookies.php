<?php

/*
 * R-47 perf (DEVELOPMENT ONLY): signed-in session cookies for the given people in the perf world, as JSON
 * {email: "name=value"}, for scripts/perf/frontend.mjs. Same guards as world.php.
 *   sh scripts/perf/env.sh php scripts/perf/cookies.php demo.matric@example.test demo.workspace.tutor@example.test
 */

use App\Models\User;
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

$out = [];
foreach (array_slice($argv, 1) as $email) {
    $user = User::query()->where('email', $email)->firstOrFail();
    $id = Str::random(40);
    DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'perf-frontend', 'last_activity' => now()->getTimestamp(),
        'payload' => base64_encode(json_encode(['_token' => Str::random(40), 'login_web_'.sha1(SessionGuard::class) => $user->id, '_flash' => ['old' => [], 'new' => []]]))]);
    $name = config('session.cookie');
    $out[$email] = $name.'='.app('encrypter')->encrypt(CookieValuePrefix::create($name, app('encrypter')->getKey()).$id, false);
}
echo json_encode($out, JSON_PRETTY_PRINT).PHP_EOL;
