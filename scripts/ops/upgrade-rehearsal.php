<?php

/*
 * Upgrade rehearsal, the PHP half of scripts/ops/upgrade-rehearsal.sh (read that file first).
 *
 *   php scripts/ops/upgrade-rehearsal.php seed      fill a database at the pre-R-47 schema with rows the way the old app
 *                                                   wrote them; prints JSON (ids, counts, a snapshot of every legacy row)
 *   php scripts/ops/upgrade-rehearsal.php verify    after the R-47 migrations and academic:sync, read that JSON on stdin and
 *                                                   check that no row was lost or changed, the money and subject mappings
 *                                                   hold, and the trunk's models and services read every legacy row
 *
 * Seeds only through DB::table (the old schema has no models in this code base). Refuses to run unless REHEARSAL=1, which
 * only the shell script sets, after pointing the app at a throw-away database and a temporary storage folder.
 */

use App\Domain\Academic\Models\Subject;
use App\Domain\Academic\Services\LegacySubjectMapper;
use App\Domain\Marketplace\Contracts\TutorDirectory;
use App\Enums\SessionStatus;
use App\Models\LessonResource;
use App\Models\SessionRating;
use App\Models\TutorProfile;
use App\Models\TutorSession;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Laravel's handler may render an uncaught exception and still exit 0 in a plain script: make any error a failure.
set_exception_handler(function (Throwable $e): void {
    fwrite(STDERR, 'upgrade-rehearsal.php: '.get_class($e).': '.$e->getMessage()."\n");
    exit(1);
});

if (getenv('REHEARSAL') !== '1') {
    fwrite(STDERR, "upgrade-rehearsal.php refuses: run it through scripts/ops/upgrade-rehearsal.sh, which creates a throw-away database.\n");
    exit(2);
}

$mode = $argv[1] ?? '';

/** Every table the pre-R-47 app had rows in, with the columns that must survive the upgrade unchanged. */
$legacy = [
    'users' => ['name', 'email', 'password', 'current_team_id', 'is_platform_operator', 'two_factor_secret'],
    'teams' => ['user_id', 'name', 'personal_team', 'infodot_team_uuid'],
    'team_user' => ['team_id', 'user_id', 'role'],
    'subjects' => ['name', 'level', 'is_active'],
    'tutor_profiles' => ['user_id', 'headline', 'bio', 'qualifications', 'hourly_rate', 'currency', 'timezone', 'status', 'reviewed_by', 'rejected_reason', 'suspended_reason', 'rating', 'total_sessions'],
    'tutor_subject' => ['tutor_profile_id', 'subject_id'],
    'tutor_availability_windows' => ['tutor_profile_id', 'weekday', 'start_time', 'end_time'],
    'tutor_blocked_dates' => ['tutor_profile_id', 'date', 'reason'],
    'tutor_sessions' => ['tutor_profile_id', 'student_id', 'subject_id', 'status', 'delivery', 'starts_at', 'duration_minutes', 'rate', 'currency', 'notes'],
    'session_ratings' => ['session_id', 'rated_by', 'rating', 'review'],
    'lesson_resources' => ['session_id', 'uploader_id', 'title', 'file_path', 'file_type'],
    'notifications' => ['type', 'notifiable_type', 'notifiable_id', 'data', 'read_at'],
];

/** One row as comparable scalars (pivot tables have no id: their key is the whole row). */
$snapshot = function (string $table, array $columns): array {
    $rows = [];
    $key = Schema::hasColumn($table, 'id') ? 'id' : null;
    $query = DB::table($table);
    $key ? $query->orderBy('id') : $query->orderBy($columns[0])->orderBy($columns[1]);

    foreach ($query->get() as $row) {
        $values = [];
        foreach ($columns as $column) {
            $value = $row->{$column};
            $values[$column] = match (true) {
                $value === null => null,
                is_bool($value) => (int) $value,
                $column === 'starts_at' || $column === 'read_at' => substr((string) $value, 0, 19),
                $column === 'date' => substr((string) $value, 0, 10),
                $column === 'hourly_rate' || $column === 'rate' || $column === 'rating' && $table === 'tutor_profiles' => number_format((float) $value, 2, '.', ''),
                default => (string) $value,
            };
        }
        $rows[$key ? (string) $row->id : implode('-', $values)] = $values;
    }

    return $rows;
};

if ($mode === 'seed') {
    $now = now()->subDays(20)->startOfMinute();
    $ids = [];

    $user = function (string $key, string $name, bool $operator = false) use (&$ids, $now): int {
        $id = DB::table('users')->insertGetId([
            'name' => $name, 'email' => "{$key}@legacy.test", 'password' => bcrypt('legacy-'.$key),
            'email_verified_at' => $now, 'is_platform_operator' => $operator, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $team = DB::table('teams')->insertGetId(['user_id' => $id, 'name' => "{$name}'s Team", 'personal_team' => true, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('users')->where('id', $id)->update(['current_team_id' => $team]);

        return $ids[$key] = $id;
    };

    $user('op', 'Nomvula Operator', true);
    foreach (['t1' => 'Thandi Mokoena', 't2' => 'Pieter van Wyk', 't3' => 'Ayesha Patel', 't4' => 'Sizwe Dlamini'] as $key => $name) {
        $user($key, $name);
    }
    foreach (['s1' => 'Lerato Learner', 's2' => 'Bongani Learner', 's3' => 'Megan Learner', 's4' => 'Zanele Learner'] as $key => $name) {
        $user($key, $name);
    }
    // A learner who switched on two-factor sign-in before the upgrade (encrypted columns must still decrypt).
    DB::table('users')->where('id', $ids['s3'])->update(['two_factor_secret' => encrypt('LEGACYTWOFACTOR1'), 'two_factor_recovery_codes' => encrypt(json_encode(['aaaa-bbbb'])), 'two_factor_confirmed_at' => $now]);

    // A company team linked to InfoDot, with a tutor as a member.
    $company = DB::table('teams')->insertGetId(['user_id' => $ids['t1'], 'name' => 'Mokoena Tutoring', 'personal_team' => false, 'infodot_team_uuid' => (string) Str::uuid(), 'created_at' => $now, 'updated_at' => $now]);
    DB::table('team_user')->insert(['team_id' => $company, 'user_id' => $ids['t2'], 'role' => 'editor', 'created_at' => $now, 'updated_at' => $now]);

    // Subjects as the old operator screen let people type them: duplicate names across bands, odd capitals, a level
    // string no academic level maps to, a subject the catalogue does not have, and a switched-off one. Inserted in this
    // order so the high-school Mathematics row is the oldest (the sync adopts the oldest row with a catalogue name).
    $subjects = [];
    foreach ([
        'maths_high' => ['Mathematics', 'high_school', true],
        'maths_primary' => ['Mathematics', 'primary', true],
        'physics' => ['physical sciences', 'high_school', true],
        'accounting' => ['Accounting', 'university', true],
        'isizulu' => ['isiZulu', 'primary', true],
        'coding' => ['Coding for Kids', 'primary', true],
        'ieb' => ['Advanced Programme Maths', 'Grade 10 (IEB)', true],
        'business' => ['Business Writing', 'adult', true],
        'latin' => ['Latin', 'high_school', false],
    ] as $key => [$name, $level, $active]) {
        $subjects[$key] = DB::table('subjects')->insertGetId(['name' => $name, 'level' => $level, 'is_active' => $active, 'created_at' => $now, 'updated_at' => $now]);
    }

    // Tutors in every old state, with decimal rates (one with cents) and free-text qualifications.
    $profiles = [];
    foreach ([
        't1' => ['approved', '250.00', 'BSc Mathematics (Wits), 8 years teaching Grade 10 to 12', null, null],
        't2' => ['approved', '199.99', 'Honours in Accounting, articled clerk', null, null],
        't3' => ['pending', '180.50', 'Second-year BEd student', null, null],
        't4' => ['suspended', '320.00', null, null, 'Repeated no-shows'],
    ] as $key => [$status, $rate, $qualifications, $rejected, $suspended]) {
        $profiles[$key] = DB::table('tutor_profiles')->insertGetId([
            'user_id' => $ids[$key], 'headline' => 'Patient tutor '.strtoupper($key), 'bio' => 'I explain step by step.',
            'qualifications' => $qualifications, 'timezone' => 'Africa/Johannesburg', 'hourly_rate' => $rate, 'currency' => 'ZAR',
            'rating' => $key === 't1' ? 4.5 : 0, 'total_sessions' => $key === 't1' ? 2 : 0, 'status' => $status,
            'reviewed_by' => $status === 'pending' ? null : $ids['op'], 'reviewed_at' => $status === 'pending' ? null : $now,
            'rejected_reason' => $rejected, 'suspended_reason' => $suspended, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }
    foreach ([['t1', 'maths_high'], ['t1', 'maths_primary'], ['t1', 'physics'], ['t2', 'accounting'], ['t2', 'business'], ['t3', 'isizulu'], ['t3', 'coding'], ['t4', 'ieb']] as [$t, $s]) {
        DB::table('tutor_subject')->insert(['tutor_profile_id' => $profiles[$t], 'subject_id' => $subjects[$s]]);
    }
    foreach ([['t1', 2, '15:00', '18:00'], ['t1', 4, '15:00', '18:00'], ['t2', 6, '09:00', '12:30']] as [$t, $day, $from, $to]) {
        DB::table('tutor_availability_windows')->insert(['tutor_profile_id' => $profiles[$t], 'weekday' => $day, 'start_time' => $from, 'end_time' => $to, 'created_at' => $now, 'updated_at' => $now]);
    }
    DB::table('tutor_blocked_dates')->insert(['tutor_profile_id' => $profiles['t1'], 'date' => now()->addDays(10)->toDateString(), 'reason' => 'Exam invigilation', 'created_at' => $now, 'updated_at' => $now]);

    // Lessons in every old status. The pre-R-47 app had no offerings, payments or guardians.
    $sessions = [];
    foreach ([
        'pending' => ['t1', 's1', 'maths_high', 'pending', 'online', 5, '250.00', 'Help with functions'],
        'confirmed' => ['t1', 's2', 'physics', 'confirmed', 'in_person', 3, '250.00', null],
        'completed_a' => ['t1', 's1', 'maths_high', 'completed', 'online', -7, '250.00', 'Trig identities'],
        'completed_b' => ['t2', 's3', 'accounting', 'completed', 'online', -6, '199.99', null],
        'cancelled' => ['t2', 's4', 'business', 'cancelled', 'online', -4, '199.99', 'Learner cancelled'],
        'no_show' => ['t1', 's2', 'maths_primary', 'no_show', 'online', -9, '250.00', null],
        'suspended_tutor' => ['t4', 's4', 'ieb', 'completed', 'online', -12, '320.00', null],
    ] as $key => [$t, $s, $subject, $status, $delivery, $days, $rate, $notes]) {
        $sessions[$key] = DB::table('tutor_sessions')->insertGetId([
            'tutor_profile_id' => $profiles[$t], 'student_id' => $ids[$s], 'subject_id' => $subjects[$subject], 'status' => $status,
            'delivery' => $delivery, 'starts_at' => now()->startOfHour()->addDays($days)->setTime(15, 0), 'duration_minutes' => 60,
            'rate' => $rate, 'currency' => 'ZAR', 'notes' => $notes, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    foreach ([['completed_a', 's1', 5, 'Clear and patient.'], ['completed_b', 's3', 3, null], ['suspended_tutor', 's4', 1, 'Did not explain anything.']] as [$session, $by, $rating, $review]) {
        DB::table('session_ratings')->insert(['session_id' => $sessions[$session], 'rated_by' => $ids[$by], 'rating' => $rating, 'review' => $review, 'created_at' => $now, 'updated_at' => $now]);
    }

    // Lesson files on the private disk, written the way the old controller stored them.
    $files = [];
    foreach ([['completed_a', 't1', 'Trig worksheet', 'pdf', "%PDF-1.4\nlegacy worksheet\n%%EOF"], ['confirmed', 's2', 'My homework photo', 'png', "\x89PNG\r\n\x1a\nlegacy"]] as [$session, $by, $title, $ext, $bytes]) {
        $path = Str::random(24).'.'.$ext;
        Storage::disk('lesson-resources')->put($path, $bytes);
        $files[$path] = hash('sha256', $bytes);
        DB::table('lesson_resources')->insert(['session_id' => $sessions[$session], 'uploader_id' => $ids[$by], 'title' => $title, 'file_path' => $path, 'file_type' => $ext === 'pdf' ? 'application/pdf' : 'image/png', 'created_at' => $now, 'updated_at' => $now]);
    }

    DB::table('notifications')->insert([
        'id' => (string) Str::uuid(), 'type' => 'App\\Notifications\\TutorProfileDecision', 'notifiable_type' => 'App\\Models\\User',
        'notifiable_id' => $ids['t1'], 'data' => json_encode(['decision' => 'approved', 'message' => 'Your tutor profile was approved.']),
        'read_at' => null, 'created_at' => $now, 'updated_at' => $now,
    ]);

    $rows = [];
    $counts = [];
    foreach ($legacy as $table => $columns) {
        $rows[$table] = $snapshot($table, $columns);
        $counts[$table] = count($rows[$table]);
    }

    echo json_encode(['counts' => $counts, 'rows' => $rows, 'users' => $ids, 'subjects' => $subjects, 'profiles' => $profiles, 'sessions' => $sessions, 'files' => $files], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    exit(0);
}

if ($mode !== 'verify') {
    fwrite(STDERR, "usage: php scripts/ops/upgrade-rehearsal.php seed|verify < seed.json\n");
    exit(2);
}

$seed = json_decode((string) stream_get_contents(STDIN), true);
if (! is_array($seed)) {
    fwrite(STDERR, "verify needs the JSON that seed printed, on stdin.\n");
    exit(2);
}

$failures = [];
$check = function (bool $ok, string $what) use (&$failures): void {
    echo ($ok ? '  ok    ' : '  FAIL  ').$what."\n";
    if (! $ok) {
        $failures[] = $what;
    }
};

echo "== every legacy row is still there, unchanged\n";
foreach ($legacy as $table => $columns) {
    $after = $snapshot($table, $columns);
    $before = $seed['rows'][$table];
    $lost = array_diff_key($before, $after);
    $changed = array_keys(array_filter($before, fn ($row, $key) => isset($after[$key]) && $after[$key] !== $row, ARRAY_FILTER_USE_BOTH));
    $extra = count($after) - count($before) + count($lost);
    $detail = count($before).' before, '.count($after).' after';
    if ($table === 'subjects') {
        $detail .= " ({$extra} catalogue row(s) added by academic:sync)";
    }
    $first = $changed === [] ? '' : '; first changed row '.$changed[0].': '.json_encode(array_diff_assoc($before[$changed[0]], $after[$changed[0]])).' -> '.json_encode(array_diff_assoc($after[$changed[0]], $before[$changed[0]]));
    $check($lost === [] && $changed === [] && ($table === 'subjects' || $extra === 0), "{$table}: {$detail}".($lost ? '; LOST '.implode(', ', array_keys($lost)) : '').$first);
}

echo "== money: decimal rates gained exact minor-unit companions\n";
foreach (DB::table('tutor_profiles')->whereIn('id', $seed['profiles'])->orderBy('id')->get() as $p) {
    $check((int) $p->hourly_rate_minor === (int) round((float) $p->hourly_rate * 100), "tutor profile {$p->id}: hourly_rate {$p->hourly_rate} -> hourly_rate_minor {$p->hourly_rate_minor}");
}
foreach (DB::table('tutor_sessions')->whereIn('id', $seed['sessions'])->orderBy('id')->get() as $s) {
    $check((int) $s->rate_minor === (int) round((float) $s->rate * 100) && $s->price_minor === null, "lesson {$s->id} ({$s->status}): rate {$s->rate} -> rate_minor {$s->rate_minor}, no v2 price invented");
}

echo "== booking v2 defaults on legacy lessons\n";
$sessions = DB::table('tutor_sessions')->whereIn('id', $seed['sessions'])->get();
$check($sessions->every(fn ($s) => $s->kind === 'one_to_one' && $s->payment_status === 'not_required' && $s->guardian_status === 'not_required' && $s->tutor_offering_id === null), 'every legacy lesson is one_to_one, payment not_required, guardian not_required, no offering (D-310 keeps pending as the stored "requested")');
$check(DB::table('tutor_session_events')->whereIn('tutor_session_id', $seed['sessions'])->doesntExist(), 'no invented history events on legacy lessons');

echo "== reviews\n";
$check(DB::table('session_ratings')->where('status', '!=', 'published')->doesntExist(), 'legacy reviews stay published');
$refused = false;
try {
    DB::transaction(fn () => DB::table('session_ratings')->where('session_id', $seed['sessions']['completed_a'])->update(['rating' => 6]));
} catch (Throwable) {
    $refused = true;
}
$check($refused && (int) DB::table('session_ratings')->where('session_id', $seed['sessions']['completed_a'])->value('rating') === 5, 'the 1 to 5 rating rule still holds after the table was extended (a 6 is refused)');

echo "== tutors: nothing verified without a review\n";
$check(DB::table('tutor_profiles')->whereIn('id', $seed['profiles'])->whereNotNull('verification_level')->doesntExist(), 'no legacy tutor was given a verification level (qualifications stay "Self-declared, not verified")');

echo "== subjects mapped onto the catalogue (no id changed, no level string rewritten)\n";
$s = $seed['subjects'];
$row = fn (string $key) => DB::table('subjects')->find($s[$key]);
$check($row('maths_high')->slug === 'mathematics' && $row('maths_high')->canonical_subject_id === null, 'the oldest "Mathematics" row was adopted as the catalogue Mathematics (same id '.$s['maths_high'].', bookings keep pointing at it)');
$check((int) $row('maths_primary')->canonical_subject_id === (int) $s['maths_high'], 'the second "Mathematics" (primary) links to it with canonical_subject_id');
$check($row('physics')->slug !== null || $row('physics')->canonical_subject_id !== null, '"physical sciences" (lower case) is in or linked to the catalogue');
$check($row('coding')->slug !== null, '"Coding for Kids", unknown to the catalogue, became a catalogue row itself (slug '.$row('coding')->slug.')');
$check(DB::table('subjects')->whereNull('slug')->whereNull('canonical_subject_id')->doesntExist(), 'every subject row is now in the catalogue or linked to it');
$unmapped = app(LegacySubjectMapper::class)->unmappedLevels();
$check($unmapped === ['Grade 10 (IEB)'], 'the one level string with no academic mapping is reported, not rewritten: '.json_encode($unmapped));
$mappedBands = DB::table('legacy_subject_level_map')->distinct()->pluck('legacy_level')->sort()->values()->all();
$check($mappedBands === ['adult', 'high_school', 'primary', 'university'], 'the four legacy bands map onto academic levels ('.implode(', ', $mappedBands).')');
$check(Subject::query()->find($s['maths_primary'])->canonicalId() === (int) $s['maths_high'], 'Subject::canonicalId() of the linked row is the adopted row');

echo "== the trunk's models and services read every legacy row\n";
$unreadable = [];
TutorSession::query()->whereIn('id', $seed['sessions'])->with(['subject'])->get()->each(function (TutorSession $session) use (&$unreadable) {
    try {
        $status = $session->status;
        SessionStatus::from($status instanceof SessionStatus ? $status->value : (string) $status);
        $session->endsAt();
    } catch (Throwable $e) {
        $unreadable[] = "lesson {$session->id}: ".$e->getMessage();
    }
});
$check($unreadable === [], 'every legacy lesson loads through TutorSession with a valid SessionStatus'.($unreadable ? ' ('.implode('; ', $unreadable).')' : ''));
$check(TutorProfile::query()->whereIn('id', $seed['profiles'])->count() === count($seed['profiles']), 'every legacy tutor profile loads through TutorProfile');
$check(SessionRating::query()->count() === $seed['counts']['session_ratings'], 'every legacy review loads through SessionRating');

$directory = app(TutorDirectory::class)->approved();
$listed = collect($directory)->pluck('id')->map(fn ($id) => (int) $id)->all();
$check(in_array((int) $seed['profiles']['t1'], $listed, true) && ! in_array((int) $seed['profiles']['t4'], $listed, true) && ! in_array((int) $seed['profiles']['t3'], $listed, true), 'the tutor directory lists the approved legacy tutors and never the pending or suspended one');

foreach (LessonResource::query()->get() as $resource) {
    $ok = Storage::disk('lesson-resources')->exists($resource->file_path)
        && hash('sha256', (string) Storage::disk('lesson-resources')->get($resource->file_path)) === ($seed['files'][$resource->file_path] ?? null);
    $check($ok, "lesson file \"{$resource->title}\" is still on the private disk, byte for byte");
}
$check(decrypt(DB::table('users')->where('id', $seed['users']['s3'])->value('two_factor_secret')) === 'LEGACYTWOFACTOR1', 'a two-factor secret written before the upgrade still decrypts with the same APP_KEY');

echo "== new tables exist\n";
foreach (['tutor_offerings', 'tutor_session_participants', 'tutor_session_events', 'money_orders', 'money_earnings', 'safety_audit_log', 'safety_guardian_links', 'learning_courses', 'curriculum_subject_levels', 'languages', 'comms_deliveries', 'classrooms', 'community_rooms', 'ai_interactions'] as $table) {
    $check(Schema::hasTable($table), "table {$table} exists");
}

echo "\n";
if ($failures !== []) {
    echo 'UPGRADE REHEARSAL FAILED: '.count($failures)." check(s).\n";
    exit(1);
}
echo "UPGRADE REHEARSAL PASSED.\n";
