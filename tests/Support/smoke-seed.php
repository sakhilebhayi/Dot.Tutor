<?php

/*
 * Seeds the people the signed-in smoke test (tests/Support/signed-in-smoke.sh) signs in as, into the throw-away database
 * that script prepared (academic:sync has already run). Never run against a real database: it refuses unless
 * SMOKE_SEED=1 and the database is a SQLite file under the system temp folder. Prints JSON (emails and ids).
 *
 *   learner    an adult first-year university learner with an academic profile and languages
 *   child      a Grade 5 learner aged 10, linked to the guardian below through a real invitation and consent
 *   guardian   the child's parent (safety guardian link, consent recorded)
 *   tutor      an approved tutor with a teaching scope, teaching languages, open hours and a priced one-to-one offering
 *   operator   users.is_platform_operator (and the admin role when the ops roles table exists)
 *
 * Built through the domain services wherever they exist, so the smoke test sees the records the app itself would write.
 */

use App\Domain\Academic\Contracts\LanguageProfile;
use App\Domain\Academic\Models\AcademicLevel;
use App\Domain\Academic\Models\Country;
use App\Domain\Academic\Services\LearnerProfiles;
use App\Domain\Marketplace\Models\TutorOffering;
use App\Domain\Marketplace\Models\TutorTeachingScope;
use App\Domain\Ops\Access\RoleAdministration;
use App\Domain\Safety\Services\Guardians;
use App\Models\Subject;
use App\Models\TutorAvailabilityWindow;
use App\Models\TutorProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

set_exception_handler(function (Throwable $e): void {
    fwrite(STDERR, 'smoke-seed: '.get_class($e).': '.$e->getMessage()."\n");
    exit(1);
});

$database = (string) config('database.connections.sqlite.database');
$tmp = rtrim((string) realpath(sys_get_temp_dir()), '/');

if (getenv('SMOKE_SEED') !== '1' || config('database.default') !== 'sqlite' || ! str_starts_with((string) realpath($database), $tmp.'/')) {
    fwrite(STDERR, "smoke-seed refuses: it only seeds the smoke script's temporary SQLite database.\n");
    exit(2);
}

$password = (string) getenv('SMOKE_PASSWORD');
if (strlen($password) < 16) {
    fwrite(STDERR, "smoke-seed: SMOKE_PASSWORD must be set (16+ characters).\n");
    exit(2);
}

$person = function (string $key, string $name) use ($password): User {
    $user = new User;
    $user->forceFill(['name' => $name, 'email' => "{$key}@smoke.test", 'password' => Hash::make($password), 'email_verified_at' => now()])->save();
    $team = $user->ownedTeams()->create(['name' => "{$name}'s Team", 'personal_team' => true]);
    $user->forceFill(['current_team_id' => $team->id])->save();

    return $user;
};

$za = (int) Country::query()->where('code', 'ZA')->value('id');
$caps = fn (string $code) => AcademicLevel::query()->where('code', $code)->whereHas('curriculum', fn ($q) => $q->where('code', 'za-caps'))->firstOrFail();
$maths = Subject::query()->where('slug', 'mathematics')->firstOrFail();

$learnerProfile = function (User $user, int $levelId, int $age) use ($za, $maths): void {
    $born = now()->subYears($age)->subMonths(2);
    app(LearnerProfiles::class)->save($user, [
        'country_id' => $za, 'academic_level_id' => $levelId, 'subjects' => [$maths->id],
        'birth_year' => $born->year, 'birth_month' => $born->month,
        'teaching' => ['en'], 'understands' => ['en'], 'learning' => [], 'bilingual_ok' => '1',
    ]);
};

$learner = $person('learner', 'Naledi Learner');
$learnerProfile($learner, (int) AcademicLevel::query()->where('code', 'undergraduate-1')->value('id'), 19);

$child = $person('child', 'Lerato Child');
$learnerProfile($child, $caps('grade-5')->id, 10);

$guardian = $person('guardian', 'Nomvula Guardian');
$guardians = app(Guardians::class);
$invite = $guardians->invite($child, $guardian->email, 'parent');
parse_str((string) parse_url($invite['url'], PHP_URL_QUERY), $query);
$guardians->accept($invite['invitation'], (string) $query['token'], $guardian, CarbonImmutable::now()->subYears(10)->subMonths(2)->startOfMonth());

$tutorUser = $person('tutor', 'Thandeka Tutor');
$profile = new TutorProfile;
$profile->forceFill([
    'user_id' => $tutorUser->id, 'hourly_rate' => 250, 'hourly_rate_minor' => 25000, 'currency' => 'ZAR', 'status' => 'approved',
    'reviewed_at' => now(), 'headline' => 'CAPS Mathematics, Grades 4 to 12', 'bio' => 'Patient, exam-focused lessons.',
    'timezone' => 'Africa/Johannesburg', 'teaches_online' => true,
])->save();
$profile->subjects()->syncWithoutDetaching([$maths->id]);
TutorTeachingScope::query()->create([
    'tutor_profile_id' => $profile->id, 'subject_id' => $maths->id,
    'curriculum_id' => $caps('grade-4')->curriculum_id, 'level_min_id' => $caps('grade-4')->id, 'level_max_id' => $caps('grade-12')->id,
]);
app(LanguageProfile::class)->syncTeachingLanguages($tutorUser, [['code' => 'zu', 'proficiency' => 'native'], ['code' => 'en', 'proficiency' => 'fluent']]);
foreach (range(1, 7) as $weekday) {
    TutorAvailabilityWindow::query()->create(['tutor_profile_id' => $profile->id, 'weekday' => $weekday, 'start_time' => '14:00', 'end_time' => '19:00']);
}
$offering = TutorOffering::query()->create([
    'tutor_profile_id' => $profile->id, 'type' => 'one_to_one', 'title' => 'One Maths lesson', 'description' => 'One hour online.',
    'price_minor' => 25000, 'currency' => 'ZAR', 'duration_minutes' => 60, 'format' => 'online', 'cancellation_notice_hours' => 24,
    'late_cancellation_refund_percent' => 50, 'is_active' => true,
]);
$offering->subjects()->syncWithoutDetaching([$maths->id]);

$operator = $person('operator', 'Sipho Operator');
$operator->forceFill(['is_platform_operator' => true])->save();
// Operator roles arrive with the ops domain (ops:grant); grant admin through its own service when it is present.
if (class_exists(RoleAdministration::class) && Schema::hasTable('ops_role_assignments')) {
    app(RoleAdministration::class)->setRoles(null, $operator, ['admin']);
}

echo json_encode([
    'learner' => $learner->email,
    'child' => $child->email,
    'guardian' => $guardian->email,
    'tutor' => $tutorUser->email,
    'operator' => $operator->email,
    'tutor_profile_id' => $profile->id,
    'offering_id' => $offering->id,
], JSON_UNESCAPED_SLASHES), "\n";
