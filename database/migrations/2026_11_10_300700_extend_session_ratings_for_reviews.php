<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reviews v2 on session_ratings (additive):
 *  - status: published | held | removed. Only published reviews are public
 *    and count toward the tutor's average. Existing rows are published.
 *  - the tutor's public reply.
 *  - one review per BOOKING: a one-to-one booking is one session, a group
 *    booking is one seat, so the unique key widens from (session_id) to
 *    (session_id, rated_by). One-to-one sessions still take exactly one
 *    review, because only the session's student may rate it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('session_ratings', function (Blueprint $table) {
            $table->string('status', 20)->default('published');
            $table->string('held_reason', 200)->nullable();
            $table->text('tutor_reply')->nullable();
            $table->timestamp('tutor_replied_at')->nullable();
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();
            $table->index('status');
        });

        Schema::table('session_ratings', function (Blueprint $table) {
            $table->unique(['session_id', 'rated_by'], 'session_ratings_session_rater_unique');
        });

        Schema::table('session_ratings', function (Blueprint $table) {
            $table->dropUnique(['session_id']);
        });

        // SQLite rebuilds the table to add a foreign key, which drops the 1-5
        // rating triggers from 2026_10_18_100500. Put them back (no-op elsewhere:
        // PostgreSQL and MySQL keep their CHECK constraint through ALTER TABLE).
        $this->restoreSqliteRatingTriggers();

        // Reports against a review: one per reporter. Enough distinct reports hold the review for moderation.
        Schema::create('tutor_review_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_rating_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reporter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 500);
            $table->string('sink_reference', 120)->nullable();
            $table->timestamps();

            $table->unique(['session_rating_id', 'reporter_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tutor_review_reports');
        Schema::table('session_ratings', function (Blueprint $table) {
            $table->unique(['session_id']);
        });
        Schema::table('session_ratings', function (Blueprint $table) {
            $table->dropUnique('session_ratings_session_rater_unique');
            $table->dropConstrainedForeignId('moderated_by');
            $table->dropIndex(['status']);
        });
        Schema::table('session_ratings', function (Blueprint $table) {
            $table->dropColumn(['status', 'held_reason', 'tutor_reply', 'tutor_replied_at', 'moderated_at']);
        });
    }

    private function restoreSqliteRatingTriggers(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER IF NOT EXISTS session_ratings_rating_range_insert BEFORE INSERT ON session_ratings
            WHEN NEW.rating IS NULL OR NEW.rating < 1 OR NEW.rating > 5
            BEGIN SELECT RAISE(ABORT, 'session_ratings.rating must be between 1 and 5'); END
        SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER IF NOT EXISTS session_ratings_rating_range_update BEFORE UPDATE OF rating ON session_ratings
            WHEN NEW.rating IS NULL OR NEW.rating < 1 OR NEW.rating > 5
            BEGIN SELECT RAISE(ABORT, 'session_ratings.rating must be between 1 and 5'); END
        SQL);
    }
};
