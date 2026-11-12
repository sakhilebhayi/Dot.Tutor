<?php

/*
 * R-47 a11y (DEVELOPMENT ONLY): ids of the demo records the sweep visits, as JSON. Local and testing only.
 *   php scripts/a11y/ids.php
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! app()->environment('local', 'testing')) {
    fwrite(STDERR, "Development only.\n");
    exit(2);
}

$user = fn (string $email) => DB::table('users')->where('email', $email)->value('id');
$first = fn (string $table, array $where = [], string $col = 'id') => DB::table($table)->where($where)->orderBy('id')->value($col);
$profile = fn (string $email) => DB::table('tutor_profiles')->where('user_id', $user($email))->value('id');

echo json_encode([
    'thandekaProfile' => $profile('demo.thandeka@example.test'),
    'workspaceProfile' => $profile('demo.workspace.tutor@example.test'),
    'thandekaOffering' => DB::table('tutor_offerings')->where('tutor_profile_id', $profile('demo.thandeka@example.test'))->where('type', 'one_to_one')->value('id'),
    'workspaceOffering' => DB::table('tutor_offerings')->where('tutor_profile_id', $profile('demo.workspace.tutor@example.test'))->where('type', 'one_to_one')->value('id'),
    'matricLesson' => DB::table('tutor_sessions')->where('student_id', $user('demo.matric@example.test'))->value('id'),
    'workspaceLearner' => $user('demo.learner.one@example.test'),
    'course' => $first('learning_courses'),
    'courseSlug' => $first('learning_courses', [], 'slug'),
    'firstLesson' => $first('learning_lessons'),
    'assessment' => $first('learning_assessments'),
    'bank' => $first('learning_question_banks'),
    'teenRoom' => DB::table('community_posts')->where('author_id', $user('demo.teen@example.test'))->value('room_id'),
    'adultRoom' => DB::table('community_rooms')->where('audience', 'adult')->where('kind', 'subject')->whereExists(fn ($q) => $q->from('community_posts')->whereColumn('community_posts.room_id', 'community_rooms.id'))->value('id'),
    'tutorRoom' => $first('community_rooms', ['kind' => 'tutor']),
    'group' => $first('community_groups'),
    'adultQuestion' => DB::table('community_posts')->where('kind', 'question')->whereIn('room_id', DB::table('community_rooms')->where('audience', 'adult')->select('id'))->orderBy('id')->value('id'),
    'teenQuestion' => DB::table('community_posts')->where('kind', 'question')->where('author_id', $user('demo.teen@example.test'))->value('id'),
    'level' => DB::table('academic_levels')->join('curricula', 'curricula.id', '=', 'academic_levels.curriculum_id')->where('curricula.code', 'za-caps')->where('academic_levels.code', 'grade-10')->value('academic_levels.id'),
    'maths' => DB::table('subjects')->where('slug', 'mathematics')->value('id'),
    'child' => $user('demo.ops.child@example.test'),
    'incident' => $first('safety_incidents'),
    'report' => $first('safety_reports'),
    'conversation' => $first('safety_conversations'),
    'order' => DB::table('money_orders')->orderBy('id')->value('uuid'),
    'opsUser' => $user('demo.ops.learner1@example.test'),
    'pendingTutorProfile' => DB::table('tutor_profiles')->where('status', 'pending')->value('id'),
    'classroomLesson' => DB::table('tutor_sessions')->where('notes', 'Demo classroom lesson')->value('id'),
    'disputedLesson' => DB::table('tutor_sessions')->where('status', 'disputed')->value('id'),
    'workspaceConfirmed' => DB::table('tutor_sessions')->where('tutor_profile_id', $profile('demo.workspace.tutor@example.test'))->where('status', 'confirmed')->value('id'),
], JSON_PRETTY_PRINT).PHP_EOL;
