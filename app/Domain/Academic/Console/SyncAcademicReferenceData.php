<?php

namespace App\Domain\Academic\Console;

use App\Domain\Academic\Services\ReferenceDataSync;
use Illuminate\Console\Command;

/**
 * Deploy step: insert missing reference data (languages, structure, catalogue,
 * offerings, starter topics, public universities) and map legacy subjects.
 * Safe to run on every deploy: it never overwrites or deletes.
 */
class SyncAcademicReferenceData extends Command
{
    protected $signature = 'academic:sync';

    protected $description = 'Insert missing academic reference data and map legacy subjects onto the catalogue (idempotent)';

    public function handle(ReferenceDataSync $sync): int
    {
        $report = $sync->run();

        foreach ($report['created'] as $kind => $count) {
            $this->line(sprintf('  %-24s %d', $kind, $count));
        }
        if ($report['created'] === []) {
            $this->line('  Reference data already present; nothing inserted.');
        }

        $legacy = $report['legacy'];
        $this->line(sprintf('  legacy subjects linked     %d', $legacy['linked']));
        $this->line(sprintf('  legacy subjects catalogued %d', $legacy['catalogued']));
        $this->line(sprintf('  subject rows               %d before, %d after', $legacy['rows_before'], $legacy['rows_after']));

        if ($legacy['unmapped_levels'] !== []) {
            $this->warn('  Legacy level strings with no academic mapping (rows kept as they are): '.implode(', ', $legacy['unmapped_levels']));
        }

        return self::SUCCESS;
    }
}
