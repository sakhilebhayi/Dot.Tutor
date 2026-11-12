<?php

/*
 * R-47 perf (DEVELOPMENT ONLY; docs/R47_PERF.md section 2.3): every foreign key column that no index leads with.
 * PostgreSQL and SQLite do not index foreign keys by themselves, so each of these is a full scan for "rows of this
 * parent" lookups, eager loads and the ON DELETE checks. Reads the schema of the database configured in the
 * environment (run it against a migrated perf or test database):
 *
 *   sh scripts/perf/env.sh php scripts/perf/fk-indexes.php
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$missing = [];
foreach (Schema::getTableListing(schemaQualified: false) as $table) {
    $leading = [];
    foreach (Schema::getIndexes($table) as $index) {
        $leading[$index['columns'][0]] = true;
        if ($index['primary'] ?? false) {
            // A composite primary key leads with its first column only.
            $leading[$index['columns'][0]] = true;
        }
    }
    foreach (Schema::getForeignKeys($table) as $fk) {
        $column = $fk['columns'][0];
        if (! isset($leading[$column])) {
            $missing[] = ['table' => $table, 'column' => $column, 'references' => $fk['foreign_table'], 'rows' => DB::table($table)->count()];
        }
    }
}

usort($missing, fn ($a, $b) => [$b['rows'], $a['table']] <=> [$a['rows'], $b['table']]);
foreach ($missing as $m) {
    printf("%-36s %-28s -> %-26s %8d rows\n", $m['table'], $m['column'], $m['references'], $m['rows']);
}
printf("%d foreign key columns without a leading index\n", count($missing));
