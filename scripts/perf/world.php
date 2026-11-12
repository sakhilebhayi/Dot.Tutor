<?php

/*
 * R-47 performance world (DEVELOPMENT ONLY; docs/R47_PERF.md, D-1600).
 *
 *   php scripts/perf/world.php setup        fresh schema + reference data + every Demo* seeder + volume (below)
 *   php scripts/perf/world.php invariants   ledger balanced, no double-booked seat, no duplicate enrolment, ... (JSON, exit 1 on a breach)
 *   php scripts/perf/world.php stats        row counts of the volume tables
 *
 * Volume (bulk rows, every name starts with "Perf" or "Demo", every address is @example.test): 4,000 learners with
 * learner profiles, subjects, languages and age records; 400 approved tutors with scopes, teaching languages,
 * offerings and open hours; 24,000 lessons over the past 180 days and the next 21 days with 9,000 reviews; the demo
 * workspace tutor with 2,400 of those lessons, 1,800 sales, earnings and payouts; the Grade 10 Maths adult room with
 * 1,500 questions and 4,500 answers and 1,200 members; a 300-lesson course with 2,500 enrolments and the matric
 * learner half way through; 12,000 balanced ledger journals; 600 inbox notifications for the matric learner.
 *
 * Guards: refuses unless APP_ENV is local or testing AND the database is a SQLite file whose name contains "perf" or a
 * PostgreSQL database named tutor_perf_*. It migrates that database from scratch.
 */

use App\Domain\Academic\Models\AcademicLevel;
use App\Domain\Academic\Models\SubjectOffering;
use App\Domain\Community\Models\Room;
use App\Domain\Community\Services\Rooms;
use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Services\CoursePublisher;
use App\Domain\Perf\TutorInvariants;
use App\Models\Subject;
use App\Models\TutorProfile;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

function fail(string $message): never
{
    fwrite(STDERR, $message.PHP_EOL);
    exit(2);
}

if (! app()->environment('local', 'testing')) {
    fail('world.php runs only with APP_ENV=local or testing.');
}

$connection = config('database.default');
$database = (string) config("database.connections.{$connection}.database");
if (! (($connection === 'sqlite' && str_contains(basename($database), 'perf')) || ($connection === 'pgsql' && str_starts_with($database, 'tutor_perf_')))) {
    fail("Refusing: database {$connection}:{$database} is not a perf database (sqlite file named *perf* or pgsql tutor_perf_*).");
}

function insertChunks(string $table, array $rows, int $size = 400): void
{
    // Multi-row inserts need the same columns in every row: missing ones are null.
    $keys = array_fill_keys(array_keys(array_merge(...array_values($rows ?: [[]]))), null);
    $rows = array_map(fn (array $row) => array_merge($keys, $row), $rows);
    foreach (array_chunk($rows, $size) as $chunk) {
        DB::table($table)->insert($chunk);
    }
}

/** Ids of the rows just inserted into $table with ids above $after (works on both engines). */
function idsAfter(string $table, int $after): array
{
    return DB::table($table)->where('id', '>', $after)->orderBy('id')->pluck('id')->all();
}

function maxId(string $table): int
{
    return (int) DB::table($table)->max('id');
}

$command = $argv[1] ?? 'help';

if ($command === 'setup') {
    mt_srand(4747);
    $t0 = microtime(true);
    $step = function (string $what) use ($t0): void {
        fwrite(STDERR, sprintf("[%6.1fs] %s\n", microtime(true) - $t0, $what));
    };

    Artisan::call('migrate:fresh', ['--force' => true]);
    Artisan::call('academic:sync');
    foreach (['DemoStudentSeeder', 'DemoOpsSeeder', 'DemoTutorWorkspaceSeeder', 'DemoCommunitySeeder', 'DemoClassroomSeeder'] as $seeder) {
        Artisan::call('db:seed', ['--class' => $seeder, '--force' => true]);
    }
    $step('reference data and demo seeders');

    $now = now();
    $stamp = $now->toDateTimeString();
    $password = bcrypt('perf-not-used');
    $za = (int) DB::table('countries')->where('code', 'ZA')->value('id');
    $caps = (int) DB::table('curricula')->where('code', 'za-caps')->value('id');
    $higher = (int) DB::table('curricula')->where('code', 'za-higher-education')->value('id');
    $capsLevels = AcademicLevel::query()->where('curriculum_id', $caps)->orderBy('position')->pluck('id')->all();
    $higherLevels = AcademicLevel::query()->where('curriculum_id', $higher)->orderBy('position')->pluck('id')->all();
    $offeringsByLevel = SubjectOffering::query()->get(['id', 'academic_level_id', 'subject_id'])->groupBy('academic_level_id');
    $languages = DB::table('languages')->pluck('id', 'code')->all();
    $teachLangs = ['en', 'zu', 'xh', 'af', 'st', 'tn', 'nso'];
    $maths = Subject::query()->where('slug', 'mathematics')->value('id');

    // Learners: 4,000 people across Grade 1 to postgraduate, ages from 7.
    $firstUser = maxId('users');
    $rows = [];
    for ($i = 1; $i <= 4000; $i++) {
        $rows[] = ['name' => "Perf Learner {$i}", 'email' => "perf.learner{$i}@example.test", 'password' => $password, 'email_verified_at' => $stamp, 'created_at' => $stamp, 'updated_at' => $stamp, 'is_platform_operator' => false];
    }
    insertChunks('users', $rows);
    $learnerIds = idsAfter('users', $firstUser);
    $profiles = $ages = $langs = [];
    foreach ($learnerIds as $n => $uid) {
        $school = $n % 5 !== 0;
        $levelIndex = $school ? $n % count($capsLevels) : $n % count($higherLevels);
        $level = $school ? $capsLevels[$levelIndex] : $higherLevels[$levelIndex];
        $age = $school ? 7 + $levelIndex : 19 + $levelIndex;
        $born = $now->copy()->subYears($age)->subMonths(($n % 11) + 1);
        $profiles[] = ['user_id' => $uid, 'country_id' => $za, 'curriculum_id' => $school ? $caps : $higher, 'academic_level_id' => $level, 'birth_month' => $born->copy()->startOfMonth()->toDateString(), 'bilingual_ok' => true, 'onboarded_at' => $stamp, 'created_at' => $stamp, 'updated_at' => $stamp];
        $ages[] = ['user_id' => $uid, 'birth_date' => $born->toDateString(), 'source' => 'self_declared', 'created_at' => $stamp, 'updated_at' => $stamp];
        $langs[] = ['user_id' => $uid, 'language_id' => $languages['en'], 'role' => 'understands', 'created_at' => $stamp, 'updated_at' => $stamp];
        $langs[] = ['user_id' => $uid, 'language_id' => $languages[$teachLangs[$n % count($teachLangs)]], 'role' => 'prefers_teaching', 'created_at' => $stamp, 'updated_at' => $stamp];
    }
    $firstProfile = maxId('learner_profiles');
    insertChunks('learner_profiles', $profiles);
    insertChunks('safety_age_records', $ages);
    insertChunks('user_languages', $langs);
    $subjectRows = [];
    foreach (DB::table('learner_profiles')->where('id', '>', $firstProfile)->get(['id', 'academic_level_id']) as $p) {
        $offered = $offeringsByLevel->get($p->academic_level_id, collect())->values();
        foreach ($offered->take(3) as $o) {
            $subjectRows[] = ['learner_profile_id' => $p->id, 'subject_id' => $o->subject_id, 'curriculum_subject_level_id' => $o->id, 'created_at' => $stamp, 'updated_at' => $stamp];
        }
    }
    insertChunks('learner_profile_subjects', $subjectRows);
    $step('4,000 learners');

    // Tutors: 400 approved, mostly Mathematics (the busiest subject), each with scopes, languages, offerings and hours.
    $firstUser = maxId('users');
    $rows = [];
    for ($i = 1; $i <= 400; $i++) {
        $rows[] = ['name' => "Perf Tutor {$i}", 'email' => "perf.tutor{$i}@example.test", 'password' => $password, 'email_verified_at' => $stamp, 'created_at' => $stamp, 'updated_at' => $stamp, 'is_platform_operator' => false];
    }
    insertChunks('users', $rows);
    $tutorUserIds = idsAfter('users', $firstUser);
    $firstTutor = maxId('tutor_profiles');
    $levelsFor = ['verified_qualified', 'independent', 'peer', 'professional', null];
    insertChunks('tutor_profiles', array_map(fn ($uid, $n) => [
        'user_id' => $uid, 'bio' => 'Perf tutor for load checks.', 'headline' => 'Perf: Mathematics tutor '.($n + 1), 'hourly_rate' => 150 + ($n % 12) * 25,
        'hourly_rate_minor' => (150 + ($n % 12) * 25) * 100, 'currency' => 'ZAR', 'status' => 'approved', 'reviewed_at' => $now->copy()->subDays($n % 300)->toDateTimeString(),
        'timezone' => 'Africa/Johannesburg', 'teaches_online' => true, 'teaches_in_person' => $n % 4 === 0, 'in_person_area' => $n % 4 === 0 ? 'Soweto' : null,
        'verification_level' => $levelsFor[$n % 5], 'years_experience' => 1 + $n % 15, 'created_at' => $stamp, 'updated_at' => $stamp,
    ], $tutorUserIds, array_keys($tutorUserIds)));
    $tutorIds = idsAfter('tutor_profiles', $firstTutor);
    $extraSubjects = Subject::query()->whereIn('slug', ['physical-sciences', 'accounting', 'english-home-language', 'life-sciences'])->pluck('id')->all();
    $ts = $scopes = $tl = $windows = $offerRows = [];
    foreach ($tutorIds as $n => $tid) {
        $subjects = array_values(array_unique(array_filter([$maths, $extraSubjects[$n % max(1, count($extraSubjects))] ?? null])));
        foreach ($subjects as $sid) {
            $ts[] = ['tutor_profile_id' => $tid, 'subject_id' => $sid];
            $lo = $capsLevels[3 + $n % 5];
            $hi = $capsLevels[min(count($capsLevels) - 1, 8 + $n % 4)];
            $scopes[] = ['tutor_profile_id' => $tid, 'subject_id' => $sid, 'curriculum_id' => $caps, 'level_min_id' => $lo, 'level_max_id' => $hi, 'created_at' => $stamp, 'updated_at' => $stamp];
        }
        $uid = $tutorUserIds[$n];
        $tl[] = ['user_id' => $uid, 'language_id' => $languages['en'], 'role' => 'can_teach_in', 'proficiency' => 'fluent', 'created_at' => $stamp, 'updated_at' => $stamp];
        $tl[] = ['user_id' => $uid, 'language_id' => $languages[$teachLangs[1 + $n % (count($teachLangs) - 1)]], 'role' => 'can_teach_in', 'proficiency' => 'native', 'created_at' => $stamp, 'updated_at' => $stamp];
        foreach (range(1, 5) as $d) {
            $windows[] = ['tutor_profile_id' => $tid, 'weekday' => ($n + $d) % 7, 'start_time' => sprintf('%02d:00', 13 + $n % 4), 'end_time' => '20:00', 'created_at' => $stamp, 'updated_at' => $stamp];
        }
        $rate = (150 + ($n % 12) * 25) * 100;
        $offerRows[] = ['tutor_profile_id' => $tid, 'type' => 'one_to_one', 'title' => 'Perf one-to-one lesson', 'price_minor' => $rate, 'currency' => 'ZAR', 'duration_minutes' => 60, 'format' => 'online', 'capacity' => 1, 'cancellation_notice_hours' => 24, 'late_cancellation_refund_percent' => 50, 'is_active' => true, 'created_at' => $stamp, 'updated_at' => $stamp];
        $offerRows[] = ['tutor_profile_id' => $tid, 'type' => 'package', 'title' => 'Perf four-lesson package', 'price_minor' => $rate * 4 - 5000, 'currency' => 'ZAR', 'duration_minutes' => 60, 'format' => 'online', 'capacity' => 1, 'package_lessons' => 4, 'package_valid_days' => 60, 'cancellation_notice_hours' => 24, 'late_cancellation_refund_percent' => 0, 'is_active' => true, 'created_at' => $stamp, 'updated_at' => $stamp];
        if ($n % 3 === 0) {
            $offerRows[] = ['tutor_profile_id' => $tid, 'type' => 'group', 'title' => 'Perf revision group', 'price_minor' => 9000, 'currency' => 'ZAR', 'duration_minutes' => 90, 'format' => 'online', 'capacity' => 8, 'cancellation_notice_hours' => 24, 'late_cancellation_refund_percent' => 0, 'is_active' => true, 'created_at' => $stamp, 'updated_at' => $stamp];
        }
    }
    insertChunks('tutor_subject', $ts);
    insertChunks('tutor_teaching_scopes', $scopes);
    insertChunks('user_languages', $tl);
    insertChunks('tutor_availability_windows', $windows);
    $firstOffering = maxId('tutor_offerings');
    insertChunks('tutor_offerings', $offerRows);
    $offeringSubjects = DB::table('tutor_offerings')->where('id', '>', $firstOffering)->pluck('id')->map(fn ($id) => ['tutor_offering_id' => $id, 'subject_id' => $maths])->all();
    insertChunks('tutor_offering_subject', $offeringSubjects);
    $oneToOne = DB::table('tutor_offerings')->where('id', '>', $firstOffering)->where('type', 'one_to_one')->pluck('id', 'tutor_profile_id')->all();
    $step('400 tutors');

    // Lessons: 24,000 across every tutor (2,400 for the demo workspace tutor), past and future; 9,000 reviews.
    $workspace = TutorProfile::query()->whereHas('user', fn ($q) => $q->where('email', 'demo.workspace.tutor@example.test'))->firstOrFail();
    $workspaceOffer = (int) DB::table('tutor_offerings')->where('tutor_profile_id', $workspace->id)->where('type', 'one_to_one')->value('id');
    $matric = User::query()->where('email', 'demo.matric@example.test')->firstOrFail();
    $firstSession = maxId('tutor_sessions');
    $sessions = [];
    // One lesson per tutor per hour (the demo seeders' lessons included): the world never double-books a tutor, so
    // the invariants check (TutorInvariants) can judge what the load run did.
    $taken = [];
    foreach (DB::table('tutor_sessions')->get(['tutor_profile_id', 'starts_at', 'duration_minutes']) as $existing) {
        $from = (int) floor(strtotime($existing->starts_at.' UTC') / 3600);
        for ($h = $from; $h < $from + (int) ceil($existing->duration_minutes / 60) + 1; $h++) {
            $taken[$existing->tutor_profile_id.':'.$h] = true;
        }
    }
    for ($i = 0; $i < 24000; $i++) {
        $isWorkspace = $i % 10 === 0;
        $tid = $isWorkspace ? $workspace->id : $tutorIds[$i % count($tutorIds)];
        $learner = $i % 97 === 0 ? $matric->id : $learnerIds[($i * 7) % count($learnerIds)];
        $start = $now->copy()->subDays(180)->addMinutes(($i * 617) % (201 * 24 * 60))->startOfHour();
        while (isset($taken[$tid.':'.intdiv($start->getTimestamp(), 3600)]) || isset($taken[$tid.':'.(intdiv($start->getTimestamp(), 3600) - 1)])) {
            $start = $start->addHours(2);
        }
        $taken[$tid.':'.intdiv($start->getTimestamp(), 3600)] = true;
        $past = $start->isPast();
        $status = $past ? (['completed', 'completed', 'completed', 'completed', 'cancelled', 'no_show'][$i % 6]) : (['confirmed', 'confirmed', 'pending', 'awaiting_payment'][$i % 4]);
        $sessions[] = ['tutor_profile_id' => $tid, 'student_id' => $learner, 'subject_id' => $maths, 'status' => $status, 'delivery' => 'online', 'starts_at' => $start->toDateTimeString(),
            'duration_minutes' => 60, 'rate' => 250, 'rate_minor' => 25000, 'price_minor' => 25000, 'currency' => 'ZAR', 'kind' => 'one_to_one', 'payment_status' => $status === 'completed' || $status === 'confirmed' ? 'paid' : 'unpaid',
            'tutor_offering_id' => $isWorkspace ? $workspaceOffer : ($oneToOne[$tid] ?? null), 'created_at' => $start->copy()->subDays(3)->toDateTimeString(), 'updated_at' => $start->toDateTimeString()];
    }
    insertChunks('tutor_sessions', $sessions);
    $ratings = [];
    $seen = 0;
    foreach (DB::table('tutor_sessions')->where('id', '>', $firstSession)->where('status', 'completed')->orderBy('id')->get(['id', 'student_id']) as $s) {
        if ($seen++ % 2 === 0 && count($ratings) < 9000) {
            $ratings[] = ['session_id' => $s->id, 'rated_by' => $s->student_id, 'rating' => [5, 4, 5, 3, 5, 4, 2][$s->id % 7], 'review' => 'Perf review: clear explanations and good pacing.', 'status' => 'published', 'created_at' => $stamp, 'updated_at' => $stamp];
        }
    }
    insertChunks('session_ratings', $ratings);
    DB::statement('UPDATE tutor_profiles SET total_sessions = (SELECT COUNT(*) FROM tutor_sessions WHERE tutor_sessions.tutor_profile_id = tutor_profiles.id AND tutor_sessions.status = ?)', ['completed']);
    $step('24,000 lessons, 9,000 reviews');

    // Money: the workspace tutor's 1,800 sales with balanced journals (cash in = commission + seller payable), earnings,
    // and a payout history; 12,000 journals in all (other tutors' sales fill the rest).
    $workspaceUser = $workspace->user_id;
    $completed = DB::table('tutor_sessions')->where('id', '>', $firstSession)->where('status', 'completed')->orderBy('id')->get(['id', 'tutor_profile_id', 'student_id', 'starts_at']);
    $sellerOf = DB::table('tutor_profiles')->pluck('user_id', 'id')->all();
    $firstOrder = maxId('money_orders');
    $orders = [];
    $sold = $completed->take(12000)->values();
    foreach ($sold as $s) {
        $orders[] = ['uuid' => (string) Str::uuid(), 'buyer_id' => $s->student_id, 'status' => 'paid', 'currency' => 'ZAR', 'subtotal_minor' => 25000, 'total_minor' => 25000, 'paid_at' => $s->starts_at, 'created_at' => $s->starts_at, 'updated_at' => $s->starts_at];
    }
    insertChunks('money_orders', $orders);
    $orderIds = idsAfter('money_orders', $firstOrder);
    $firstLine = maxId('money_order_lines');
    $lines = [];
    foreach ($sold as $k => $s) {
        $lines[] = ['order_id' => $orderIds[$k], 'purchasable_type' => 'booking', 'purchasable_id' => $s->id, 'title' => 'Perf: Mathematics lesson', 'seller_id' => $sellerOf[$s->tutor_profile_id],
            'commission_category' => 'lesson', 'earning_release' => 'on_delivery', 'price_minor' => 25000, 'cash_minor' => 25000, 'commission_bps' => 1500, 'commission_minor' => 3750, 'seller_net_minor' => 21250,
            'created_at' => $s->starts_at, 'updated_at' => $s->starts_at];
    }
    insertChunks('money_order_lines', $lines);
    $lineIds = idsAfter('money_order_lines', $firstLine);
    $earnings = [];
    foreach ($sold as $k => $s) {
        $old = $now->copy()->subDays(30)->toDateTimeString() > $s->starts_at;
        $earnings[] = ['seller_id' => $sellerOf[$s->tutor_profile_id], 'order_line_id' => $lineIds[$k], 'source_key' => 'perf-sale:'.$lineIds[$k], 'kind' => 'sale', 'gross_minor' => 25000, 'commission_minor' => 3750,
            'charges_minor' => 0, 'net_minor' => 21250, 'currency' => 'ZAR', 'status' => $old ? 'available' : 'pending', 'release_rule' => 'on_delivery', 'release_after' => $s->starts_at, 'available_at' => $old ? $s->starts_at : null,
            'created_at' => $s->starts_at, 'updated_at' => $s->starts_at];
    }
    insertChunks('money_earnings', $earnings);
    $firstJournal = maxId('money_ledger_journals');
    $journals = [];
    foreach ($sold as $k => $s) {
        $journals[] = ['kind' => 'order_paid', 'idempotency_key' => 'order-paid:'.$orderIds[$k], 'currency' => 'ZAR', 'order_id' => $orderIds[$k], 'reason' => 'Perf: lesson sale', 'posted_at' => $s->starts_at, 'created_at' => $s->starts_at];
    }
    insertChunks('money_ledger_journals', $journals);
    $journalIds = idsAfter('money_ledger_journals', $firstJournal);
    $entries = [];
    foreach ($sold as $k => $s) {
        $j = $journalIds[$k];
        $seller = $sellerOf[$s->tutor_profile_id];
        $entries[] = ['journal_id' => $j, 'account' => 'gateway_clearing', 'account_type' => 'gateway_clearing', 'party_id' => null, 'debit_minor' => 25000, 'credit_minor' => 0, 'currency' => 'ZAR', 'created_at' => $s->starts_at];
        $entries[] = ['journal_id' => $j, 'account' => 'platform_commission', 'account_type' => 'platform_commission', 'party_id' => null, 'debit_minor' => 0, 'credit_minor' => 3750, 'currency' => 'ZAR', 'created_at' => $s->starts_at];
        $entries[] = ['journal_id' => $j, 'account' => 'tutor_pending:'.$seller, 'account_type' => 'tutor_pending', 'party_id' => $seller, 'debit_minor' => 0, 'credit_minor' => 21250, 'currency' => 'ZAR', 'created_at' => $s->starts_at];
    }
    insertChunks('money_ledger_entries', $entries, 600);
    $step(count($sold).' sales, earnings and balanced journals');

    // Community: the Grade 10 CAPS Mathematics adult room, 1,500 questions with 3 answers each, 1,200 members.
    $g10 = AcademicLevel::query()->where('curriculum_id', $caps)->where('code', 'grade-10')->value('id');
    $offering = SubjectOffering::query()->where('academic_level_id', $g10)->where('subject_id', $maths)->firstOrFail();
    $room = app(Rooms::class)->subjectRoom($offering, Room::ADULT);
    $adults = array_slice($learnerIds, 0, 1200);
    insertChunks('community_room_members', array_map(fn ($uid) => ['room_id' => $room->id, 'user_id' => $uid, 'role' => 'learner', 'status' => 'active', 'joined_at' => $stamp, 'created_at' => $stamp, 'updated_at' => $stamp], $adults));
    $firstPost = maxId('community_posts');
    $questions = [];
    for ($i = 0; $i < 1500; $i++) {
        $at = $now->copy()->subMinutes(1500 * 40 - $i * 40)->toDateTimeString();
        $questions[] = ['room_id' => $room->id, 'author_id' => $adults[$i % count($adults)], 'kind' => $i % 5 === 4 ? 'discussion' : 'question', 'title' => "Perf: question {$i} about quadratic equations",
            'body' => 'How do I complete the square when the leading coefficient is not 1? I tried dividing through but lost track of the constant.', 'original_locale' => 'en', 'status' => 'visible', 'last_activity_at' => $at, 'created_at' => $at, 'updated_at' => $at];
    }
    insertChunks('community_posts', $questions);
    $answers = [];
    foreach (idsAfter('community_posts', $firstPost) as $k => $qid) {
        foreach (range(1, 3) as $a) {
            $answers[] = ['room_id' => $room->id, 'parent_id' => $qid, 'author_id' => $adults[($k + $a * 31) % count($adults)], 'kind' => 'answer', 'body' => 'Divide every term by the leading coefficient first, then halve the new middle coefficient and square it.',
                'original_locale' => 'en', 'status' => 'visible', 'created_at' => $stamp, 'updated_at' => $stamp];
        }
    }
    insertChunks('community_posts', $answers);
    $step('room with 6,000 posts');

    // Learning: a published 300-lesson course with 2,500 enrolments; the matric learner is half way.
    $owner = User::query()->where('email', 'demo.workspace.tutor@example.test')->firstOrFail();
    $course = Course::query()->forceCreate(['owner_id' => $owner->id, 'slug' => 'perf-matric-maths', 'title' => 'Perf: Matric Mathematics, every topic', 'description' => 'Perf course with 300 lessons for load checks.',
        'subject_id' => $maths, 'curriculum_id' => $caps, 'level_id' => $capsLevels[11], 'original_locale' => 'en', 'objectives' => ['Pass Paper 1 and Paper 2'], 'prerequisites' => ['Grade 11 Mathematics'], 'workload_minutes' => 2400,
        'pricing' => 'free', 'currency' => 'ZAR', 'completion_rule' => 'all_lessons', 'status' => 'draft']);
    $firstSection = maxId('learning_course_sections');
    insertChunks('learning_course_sections', array_map(fn ($p) => ['course_id' => $course->id, 'title' => "Perf section {$p}", 'position' => $p, 'created_at' => $stamp, 'updated_at' => $stamp], range(1, 20)));
    $lessons = [];
    foreach (idsAfter('learning_course_sections', $firstSection) as $si => $sid) {
        foreach (range(1, 15) as $p) {
            $lessons[] = ['course_id' => $course->id, 'section_id' => $sid, 'title' => 'Perf lesson '.($si * 15 + $p), 'type' => 'text', 'body' => "Worked example.\n\nStep one, then step two.", 'duration_minutes' => 8, 'is_preview' => $si === 0 && $p === 1, 'position' => $p, 'created_at' => $stamp, 'updated_at' => $stamp];
        }
    }
    $firstLesson = maxId('learning_lessons');
    insertChunks('learning_lessons', $lessons);
    $lessonIds = idsAfter('learning_lessons', $firstLesson);
    app(CoursePublisher::class)->publish($course->fresh(), $owner);
    $course->refresh();
    $firstEnrol = maxId('learning_enrolments');
    $enrolled = array_merge([$matric->id], array_slice($learnerIds, 1000, 2499));
    insertChunks('learning_enrolments', array_map(fn ($uid) => ['course_id' => $course->id, 'user_id' => $uid, 'course_version_id' => $course->current_version_id, 'source' => 'free', 'status' => 'active', 'resume_lesson_id' => $lessonIds[150], 'created_at' => $stamp, 'updated_at' => $stamp], $enrolled));
    $progress = [];
    foreach (idsAfter('learning_enrolments', $firstEnrol) as $k => $eid) {
        $done = $k === 0 ? 150 : ($k % 40);
        foreach (array_slice($lessonIds, 0, $done) as $lid) {
            $progress[] = ['enrolment_id' => $eid, 'lesson_id' => $lid, 'position_seconds' => 0, 'completed_at' => $stamp, 'created_at' => $stamp, 'updated_at' => $stamp];
        }
    }
    insertChunks('learning_lesson_progress', $progress, 600);
    // 200 more published courses so the catalogue is not empty.
    $more = [];
    for ($i = 1; $i <= 200; $i++) {
        $more[] = ['owner_id' => $tutorUserIds[$i % count($tutorUserIds)], 'slug' => "perf-course-{$i}", 'title' => "Perf course {$i}", 'description' => 'Perf catalogue course.', 'subject_id' => $maths, 'curriculum_id' => $caps,
            'level_id' => $capsLevels[$i % count($capsLevels)], 'original_locale' => 'en', 'pricing' => $i % 2 ? 'free' : 'paid', 'price_minor' => $i % 2 ? null : 34900, 'currency' => 'ZAR', 'completion_rule' => 'all_lessons', 'status' => 'published', 'published_at' => $stamp, 'created_at' => $stamp, 'updated_at' => $stamp];
    }
    insertChunks('learning_courses', $more);
    $step('300-lesson course, 2,500 enrolments, 200 more courses');

    // Inbox: 600 notifications for the matric learner.
    $notes = [];
    for ($i = 0; $i < 600; $i++) {
        $notes[] = ['id' => (string) Str::uuid(), 'type' => 'App\\Domain\\Comms\\Notifications\\PerfNotice', 'notifiable_type' => User::class, 'notifiable_id' => $matric->id,
            'data' => json_encode(['category' => 'lessons', 'title' => "Perf notice {$i}", 'body' => 'Your lesson is confirmed.', 'url' => '/learn']), 'read_at' => $i % 3 ? $stamp : null, 'created_at' => $now->copy()->subMinutes($i * 37)->toDateTimeString(), 'updated_at' => $stamp];
    }
    insertChunks('notifications', $notes);
    $step('600 inbox notifications');

    if ($connection === 'pgsql') {
        DB::statement('ANALYZE');
    } else {
        DB::statement('ANALYZE');
    }
    $step('done');
    echo json_encode(['room' => $room->id, 'course' => $course->id, 'lesson' => $lessonIds[150], 'workspace_tutor' => $workspace->id, 'tutor_sample' => $tutorIds[7]], JSON_PRETTY_PRINT).PHP_EOL;
    exit(0);
}

if ($command === 'invariants') {
    $result = TutorInvariants::check();
    echo json_encode($result, JSON_PRETTY_PRINT).PHP_EOL;
    exit($result['ok'] ? 0 : 1);
}

if ($command === 'stats') {
    $out = [];
    foreach (['users', 'learner_profiles', 'tutor_profiles', 'tutor_sessions', 'session_ratings', 'community_posts', 'learning_lessons', 'learning_enrolments', 'learning_lesson_progress', 'money_orders', 'money_ledger_journals', 'money_ledger_entries', 'money_earnings', 'notifications'] as $t) {
        $out[$t] = DB::table($t)->count();
    }
    echo json_encode($out, JSON_PRETTY_PRINT).PHP_EOL;
    exit(0);
}

echo "usage: php scripts/perf/world.php setup | invariants | stats\n";
