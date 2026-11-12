<?php

// Direct setup against the throwaway database of the browser E2E suite (tests/e2e/run.mjs): people in their roles,
// "time travel" by moving timestamps back, and reads of configuration. Usage:
//   php tests/e2e/support/world.php <command> '<json>'   -> prints one JSON line.
// It refuses to run against any database but the suite's own temporary file. Everything a journey proves is done in
// the browser; this file only makes the people a step assumes already exist (and says so in docs/R47_QA.md).

use App\Domain\Academic\Contracts\LanguageProfile;
use App\Domain\Academic\Models\AcademicLevel;
use App\Domain\Academic\Models\Country;
use App\Domain\Academic\Services\LearnerProfiles;
use App\Domain\Marketplace\Models\TutorOffering;
use App\Domain\Marketplace\Models\TutorTeachingScope;
use App\Domain\Ops\Access\RoleAdministration;
use App\Models\Subject;
use App\Models\TutorAvailabilityWindow;
use App\Models\TutorProfile;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! str_contains((string) config('database.connections.sqlite.database'), 'dot-tutor-e2e-') || app()->isProduction()) {
    fwrite(STDERR, "refusing to run outside the e2e database\n");
    exit(2);
}

$cmd = $argv[1] ?? '';
$in = json_decode($argv[2] ?? '{}', true) ?: [];
$out = [];

$makeUser = function (array $in): User {
    $user = User::factory()->withPersonalTeam()->create([
        'name' => $in['name'] ?? 'E2E Person', 'email' => $in['email'], 'password' => Hash::make($in['password'] ?? 'e2e-pass-12345'),
    ]);
    $user->forceFill(['email_verified_at' => now()])->save();

    return $user;
};

$levelByCode = fn (string $code, string $curriculum = 'za-caps'): AcademicLevel => AcademicLevel::query()
    ->whereHas('curriculum', fn ($q) => $q->where('code', $curriculum))->where('code', $code)->firstOrFail();

switch ($cmd) {
    case 'user':
        $user = $makeUser($in);
        $out = ['id' => $user->id, 'email' => $user->email];
        break;

    case 'operator':
        // Roles written as `php artisan ops:grant` writes them (RoleAdministration with no actor).
        $user = $makeUser($in);
        app(RoleAdministration::class)->setRoles(null, $user, $in['roles'] ?? ['admin']);
        $out = ['id' => $user->id, 'email' => $user->email];
        break;

    case 'learner':
        // A learner who already finished onboarding (the student journey does onboarding in the browser instead):
        // South Africa, a CAPS level, subjects, teaching languages and a birth month for the age given.
        $user = $makeUser($in);
        $level = $levelByCode($in['level'] ?? 'grade-11', $in['curriculum'] ?? 'za-caps');
        $born = now()->subYears((int) ($in['age'] ?? 19))->subMonths(2);
        app(LearnerProfiles::class)->save($user, [
            'country_id' => Country::query()->where('code', 'ZA')->value('id'), 'academic_level_id' => $level->id,
            'subjects' => [Subject::query()->where('slug', $in['subject'] ?? 'mathematics')->value('id')],
            'birth_year' => $born->year, 'birth_month' => $born->month,
            'teaching' => $in['languages'] ?? ['en'], 'understands' => ['en'], 'learning' => [], 'bilingual_ok' => '1',
        ]);
        $out = ['id' => $user->id, 'email' => $user->email, 'level_id' => $level->id];
        break;

    case 'ready-tutor':
        // A tutor who passed onboarding and review before this test (the tutor journey does all of it in the
        // browser): approved profile, CAPS teaching scope, teaching languages, open every day 06:00 to 22:00, and the
        // offerings asked for. Written as DemoTutorWorkspaceSeeder writes them (models, no shortcuts in money).
        $user = $makeUser($in);
        $subject = Subject::query()->where('slug', $in['subject'] ?? 'mathematics')->firstOrFail();
        $profile = TutorProfile::query()->create([
            'user_id' => $user->id, 'headline' => $in['headline'] ?? 'CAPS Mathematics, Grades 10 to 12', 'bio' => 'E2E fixture tutor. I teach FET Mathematics.',
            'hourly_rate_minor' => $in['price'] ?? 25000, 'hourly_rate' => ($in['price'] ?? 25000) / 100, 'currency' => 'ZAR', 'timezone' => 'Africa/Johannesburg', 'status' => 'approved',
            'teaches_online' => true,
        ]);
        $profile->subjects()->syncWithoutDetaching([$subject->id]);
        $min = $levelByCode($in['level_min'] ?? 'grade-10');
        $max = $levelByCode($in['level_max'] ?? 'grade-12');
        TutorTeachingScope::query()->create(['tutor_profile_id' => $profile->id, 'subject_id' => $subject->id, 'curriculum_id' => $min->curriculum_id, 'level_min_id' => $min->id, 'level_max_id' => $max->id]);
        app(LanguageProfile::class)->syncTeachingLanguages($user, array_map(fn ($c) => ['code' => $c, 'proficiency' => 'fluent'], $in['languages'] ?? ['en']));
        foreach (range(1, 7) as $day) {
            TutorAvailabilityWindow::query()->create(['tutor_profile_id' => $profile->id, 'weekday' => $day, 'start_time' => '06:00', 'end_time' => '22:00']);
        }
        $offerings = [];
        foreach ($in['offerings'] ?? [['type' => 'one_to_one', 'title' => 'Maths lesson', 'price_minor' => 25000]] as $o) {
            $offering = TutorOffering::query()->create($o + [
                'tutor_profile_id' => $profile->id, 'currency' => 'ZAR', 'format' => 'online', 'capacity' => 1, 'duration_minutes' => 60,
                'cancellation_notice_hours' => 24, 'late_cancellation_refund_percent' => 50, 'is_active' => true,
            ]);
            $offering->subjects()->syncWithoutDetaching([$subject->id]);
            $offerings[] = $offering->id;
        }
        $out = ['id' => $user->id, 'email' => $user->email, 'profile_id' => $profile->id, 'offerings' => $offerings, 'subject_id' => $subject->id];
        break;

    case 'shift':
        // Time travel: move timestamp columns of one row back by N seconds (the app's clock is the real one).
        $row = DB::table($in['table'])->where('id', $in['id'])->first();
        $updates = [];
        foreach ($in['columns'] as $column) {
            if ($row->{$column} !== null) {
                $updates[$column] = Carbon::parse($row->{$column})->subSeconds((int) $in['seconds'])->format('Y-m-d H:i:s');
            }
        }
        DB::table($in['table'])->where('id', $in['id'])->update($updates);
        $out = ['ok' => true, 'updated' => $updates];
        break;

    case 'update':
        DB::table($in['table'])->where($in['where'])->update($in['set']);
        $out = ['ok' => true];
        break;

    case 'password':
        // A person provisioned by InfoDot has a random password; the route sweep signs them in with the local form.
        User::query()->whereKey($in['id'])->update(['password' => Hash::make($in['password'] ?? 'e2e-pass-12345')]);
        $out = ['ok' => true];
        break;

    case 'config':
        $out = ['value' => config($in['key'])];
        break;

    default:
        fwrite(STDERR, "unknown command\n");
        exit(1);
}

echo json_encode($out, JSON_UNESCAPED_SLASHES), "\n";
