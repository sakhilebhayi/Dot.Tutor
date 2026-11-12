<?php

namespace App\Domain\Safety\Console;

use App\Domain\Safety\Services\DatabaseAuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AuditVerify extends Command
{
    protected $signature = 'safety:audit-verify';

    protected $description = 'Walk the audit log hash chain and report the first row that was changed or removed';

    public function handle(DatabaseAuditLog $log): int
    {
        $broken = $log->verify();

        if ($broken !== null) {
            Log::critical('safety audit chain broken', ['first_broken_id' => $broken]);
            $this->error("The audit chain is broken at row {$broken}.");

            return self::FAILURE;
        }

        $this->info('The audit chain is intact.');

        return self::SUCCESS;
    }
}
