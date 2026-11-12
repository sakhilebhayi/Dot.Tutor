<?php

namespace App\Domain\Safety\Console;

use App\Domain\Safety\Services\Incidents;
use Illuminate\Console\Command;

class EscalateIncidents extends Command
{
    protected $signature = 'safety:escalate-incidents';

    protected $description = 'Escalate safeguarding incidents nobody acknowledged in time and notify every operator';

    public function handle(Incidents $incidents): int
    {
        $count = $incidents->escalateOverdue();
        $this->info("Escalated {$count} incident(s).");

        return self::SUCCESS;
    }
}
