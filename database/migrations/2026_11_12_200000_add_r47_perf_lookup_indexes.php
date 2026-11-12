<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-47 perf (D-1607, docs/R47_PERF.md section 2.3): indexes for the parent-to-children lookups the screens make.
 * Neither PostgreSQL nor SQLite indexes a foreign key by itself; scripts/perf/fk-indexes.php listed 180 foreign key
 * columns no index leads with. These are the ones a page, a poll or a list reads by (lines of an order, a payout's
 * earnings, a learner's enrolments, a course's lessons, a lesson's shared files on every classroom poll, ...).
 * Columns only written for the record (who reviewed, who posted) are left unindexed on purpose: an index costs every
 * insert and nothing reads by them.
 */
return new class extends Migration
{
    /** @var array<string, list<list<string>>> table => index column lists */
    private const INDEXES = [
        // Money: an order's lines, a journal's entries, an earning's line, a payout's earnings, a tutor's payouts.
        'money_order_lines' => [['order_id']],
        'money_ledger_entries' => [['journal_id']],
        'money_earnings' => [['order_line_id'], ['payout_id'], ['created_at']],
        // Date windows of the operations overview (orders paid, lessons requested, earnings recorded in 30 days).
        'money_orders' => [['paid_at']],
        'money_payouts' => [['payee_id', 'status'], ['batch_id']],
        'money_refunds' => [['order_id'], ['order_line_id']],
        'money_disputes' => [['order_id']],
        'money_receipts' => [['buyer_id']],
        'money_credit_grants' => [['user_id']],
        // Bookings: a series' or a package's lessons, the reviews a learner wrote, a tutor's scopes and series.
        'tutor_sessions' => [['tutor_booking_series_id'], ['tutor_package_purchase_id'], ['tutor_offering_id'], ['created_at']],
        'session_ratings' => [['rated_by']],
        'tutor_teaching_scopes' => [['tutor_profile_id']],
        'tutor_booking_series' => [['tutor_profile_id'], ['learner_id']],
        'tutor_package_purchases' => [['tutor_profile_id']],
        'tutor_qualification_evidence' => [['tutor_qualification_id']],
        'tutor_subject' => [['subject_id']],
        // Learning: a learner's enrolments, a course's outline, an owner's courses, attempts and their answers.
        'learning_enrolments' => [['user_id', 'status']],
        'learning_course_sections' => [['course_id', 'position']],
        'learning_lessons' => [['course_id', 'position'], ['section_id', 'position']],
        'learning_courses' => [['owner_id']],
        'learning_materials' => [['course_id'], ['lesson_id']],
        'learning_attempts' => [['assessment_id']],
        'learning_attempt_answers' => [['attempt_id']],
        'learning_assessments' => [['course_id'], ['owner_id']],
        'learning_questions' => [['bank_id']],
        'learning_question_banks' => [['owner_id']],
        'learning_flashcards' => [['set_id']],
        'learning_flashcard_sets' => [['owner_id']],
        'learning_assignments' => [['learner_id']],
        'learning_certificates' => [['user_id']],
        'learning_resources' => [['owner_id']],
        'learning_resource_reviews' => [['resource_id']],
        'learning_resource_reports' => [['resource_id']],
        // Rooms: a group's posts, accepted answers (badges), a post's revisions, a group's tasks and revision list.
        'community_posts' => [['group_id', 'status'], ['accepted_answer_id']],
        'community_post_revisions' => [['post_id']],
        'community_tasks' => [['group_id']],
        'community_revision_items' => [['group_id']],
        // Classroom: the lesson's shared files, read on every poll (max id) and when the list changes.
        'lesson_resources' => [['session_id', 'id']],
        // Safety: consents of a guardian link (read on most requests by a minor), a person's reports and incidents.
        'safety_consents' => [['guardian_link_id']],
        'safety_reports' => [['reporter_id'], ['reported_user_id'], ['incident_id']],
        'safety_incidents' => [['learner_id']],
        'safety_incident_events' => [['incident_id']],
        'safety_moderation_actions' => [['target_user_id'], ['report_id'], ['incident_id']],
        'safety_integrity_cases' => [['subject_user_id']],
        'safety_messages' => [['sender_id']],
        'safety_message_attachments' => [['message_id']],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($indexes as $columns) {
                if (! Schema::hasColumns($table, $columns) || Schema::hasIndex($table, $columns)) {
                    continue;
                }
                Schema::table($table, fn (Blueprint $t) => $t->index($columns, $this->name($table, $columns)));
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($indexes as $columns) {
                if (Schema::hasIndex($table, $this->name($table, $columns))) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropIndex($this->name($table, $columns)));
                }
            }
        }
    }

    private function name(string $table, array $columns): string
    {
        return substr($table.'_'.implode('_', $columns).'_perf_idx', 0, 63);
    }
};
