<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deleting an account used to cascade through sessions, ratings and lesson
 * resources, erasing the other person's history with it. Chosen fix (least
 * risky of the options considered): keep the rows, drop the pointer.
 *
 *  - A person pointer (student, rater, uploader, tutor's user) becomes
 *    nullable + ON DELETE SET NULL. The row survives; the UI says "Deleted
 *    account".
 *  - A pointer to a thing that history depends on (a session's tutor profile
 *    and subject) becomes ON DELETE RESTRICT, so nothing can silently cascade
 *    a session away. Subjects are deactivated, never deleted; tutor profiles
 *    outlive their user (see App\Actions\Jetstream\DeleteUser).
 *
 * Safe on existing data: every change widens (NOT NULL -> NULL) or swaps the
 * delete action; no value is rewritten, so no existing row can violate the
 * new constraints. Each step is its own Schema::table so SQLite's
 * rebuild-the-table path and PostgreSQL's ALTER both handle it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->repoint('tutor_profiles', 'user_id', 'users', nullable: true, onDelete: 'set null');
        $this->repoint('tutor_sessions', 'student_id', 'users', nullable: true, onDelete: 'set null');
        $this->repoint('session_ratings', 'rated_by', 'users', nullable: true, onDelete: 'set null');
        $this->repoint('lesson_resources', 'uploader_id', 'users', nullable: true, onDelete: 'set null');
        $this->repoint('tutor_sessions', 'tutor_profile_id', 'tutor_profiles', nullable: false, onDelete: 'restrict');
        $this->repoint('tutor_sessions', 'subject_id', 'subjects', nullable: false, onDelete: 'restrict');
    }

    public function down(): void
    {
        // Columns stay nullable on the way down: rows already holding NULL
        // would otherwise make the rollback fail. Only the delete actions revert.
        $this->repoint('tutor_sessions', 'subject_id', 'subjects', nullable: false, onDelete: 'cascade');
        $this->repoint('tutor_sessions', 'tutor_profile_id', 'tutor_profiles', nullable: false, onDelete: 'cascade');
        $this->repoint('lesson_resources', 'uploader_id', 'users', nullable: true, onDelete: 'cascade');
        $this->repoint('session_ratings', 'rated_by', 'users', nullable: true, onDelete: 'cascade');
        $this->repoint('tutor_sessions', 'student_id', 'users', nullable: true, onDelete: 'cascade');
        $this->repoint('tutor_profiles', 'user_id', 'users', nullable: true, onDelete: 'cascade');
    }

    private function repoint(string $table, string $column, string $references, bool $nullable, string $onDelete): void
    {
        Schema::table($table, function (Blueprint $t) use ($column) {
            $t->dropForeign([$column]);
        });

        Schema::table($table, function (Blueprint $t) use ($column, $nullable) {
            $col = $t->unsignedBigInteger($column);
            $nullable ? $col->nullable()->change() : $col->change();
        });

        Schema::table($table, function (Blueprint $t) use ($column, $references, $onDelete) {
            $t->foreign($column)->references('id')->on($references)->onDelete($onDelete);
        });
    }
};
