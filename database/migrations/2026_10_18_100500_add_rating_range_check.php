<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * session_ratings.rating is validated 1-5 in the controller; this makes the
 * database refuse anything else too (a tinker session, a future importer).
 *
 *  - PostgreSQL: a CHECK constraint added NOT VALID, which enforces every
 *    new write without scanning or rejecting rows that already exist.
 *  - MySQL/MariaDB: a plain CHECK (8.0.16+ / 10.2+).
 *  - SQLite cannot add a CHECK to an existing table without rebuilding it,
 *    so insert/update triggers raise the same error.
 */
return new class extends Migration
{
    public function up(): void
    {
        match (DB::connection()->getDriverName()) {
            'pgsql' => DB::statement('ALTER TABLE session_ratings ADD CONSTRAINT session_ratings_rating_range CHECK (rating BETWEEN 1 AND 5) NOT VALID'),
            'mysql', 'mariadb' => DB::statement('ALTER TABLE session_ratings ADD CONSTRAINT session_ratings_rating_range CHECK (rating BETWEEN 1 AND 5)'),
            'sqlite' => $this->sqliteTriggers(),
            default => null,
        };
    }

    public function down(): void
    {
        match (DB::connection()->getDriverName()) {
            'pgsql' => DB::statement('ALTER TABLE session_ratings DROP CONSTRAINT IF EXISTS session_ratings_rating_range'),
            'mysql' => DB::statement('ALTER TABLE session_ratings DROP CHECK session_ratings_rating_range'),
            'mariadb' => DB::statement('ALTER TABLE session_ratings DROP CONSTRAINT session_ratings_rating_range'),
            'sqlite' => $this->dropSqliteTriggers(),
            default => null,
        };
    }

    private function sqliteTriggers(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER session_ratings_rating_range_insert BEFORE INSERT ON session_ratings
            WHEN NEW.rating IS NULL OR NEW.rating < 1 OR NEW.rating > 5
            BEGIN SELECT RAISE(ABORT, 'session_ratings.rating must be between 1 and 5'); END
        SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER session_ratings_rating_range_update BEFORE UPDATE OF rating ON session_ratings
            WHEN NEW.rating IS NULL OR NEW.rating < 1 OR NEW.rating > 5
            BEGIN SELECT RAISE(ABORT, 'session_ratings.rating must be between 1 and 5'); END
        SQL);
    }

    private function dropSqliteTriggers(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS session_ratings_rating_range_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS session_ratings_rating_range_update');
    }
};
