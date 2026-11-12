<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R-47 integration (D-1406). Marketplace and learning stored academic ids (curriculum, level, topic) as plain
 * indexed integers because the academic tables were on another branch. With every branch merged, the keys are
 * added (restrict on delete: academic rows are deactivated, never deleted).
 *
 * Safe on a populated database: nothing is rewritten. Before any key is added, every stored id is checked against
 * its parent table; if any id points nowhere the migration stops and names them, so an operator decides (until
 * this release every scope, course and resource was validated by seams that refused academic ids, so none are
 * expected). marketplace tutor_offerings.level_ids is a JSON list and cannot carry a key; the academic seam
 * validates it on write.
 */
return new class extends Migration
{
    /** table => [column => parent table] */
    private const KEYS = [
        'tutor_teaching_scopes' => ['curriculum_id' => 'curricula', 'level_min_id' => 'academic_levels', 'level_max_id' => 'academic_levels'],
        'learning_courses' => ['curriculum_id' => 'curricula', 'level_id' => 'academic_levels'],
        'learning_resources' => ['curriculum_id' => 'curricula', 'level_id' => 'academic_levels', 'topic_id' => 'academic_topics'],
        'learning_question_banks' => ['topic_id' => 'academic_topics'],
    ];

    public function up(): void
    {
        $orphans = [];
        foreach (self::KEYS as $table => $columns) {
            foreach ($columns as $column => $parent) {
                $ids = DB::table($table)->whereNotNull($column)
                    ->whereNotExists(fn ($q) => $q->selectRaw('1')->from($parent)->whereColumn("{$parent}.id", "{$table}.{$column}"))
                    ->distinct()->pluck($column)->all();
                if ($ids !== []) {
                    $orphans[] = "{$table}.{$column} -> {$parent}: ".implode(', ', $ids);
                }
            }
        }
        if ($orphans !== []) {
            throw new RuntimeException("Academic ids that point nowhere; fix or null them, then migrate again:\n".implode("\n", $orphans));
        }

        foreach (self::KEYS as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($table, $columns) {
                foreach ($columns as $column => $parent) {
                    $blueprint->foreign($column, "{$table}_{$column}_academic_fk")->references('id')->on($parent)->restrictOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::KEYS as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($table, $columns) {
                foreach (array_keys($columns) as $column) {
                    // SQLite finds a key by its columns (it rebuilds the table); the others by name.
                    $blueprint->dropForeign(Schema::getConnection()->getDriverName() === 'sqlite' ? [$column] : "{$table}_{$column}_academic_fk");
                }
            });
        }
    }
};
