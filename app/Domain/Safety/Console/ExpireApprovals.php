<?php

namespace App\Domain\Safety\Console;

use App\Domain\Safety\Contracts\GuardianApprovals;
use App\Domain\Safety\Services\DatabaseGuardianApprovals;
use Illuminate\Console\Command;

class ExpireApprovals extends Command
{
    protected $signature = 'safety:expire-approvals';

    protected $description = 'Mark guardian approval requests that passed their expiry as expired';

    public function handle(GuardianApprovals $approvals): int
    {
        $count = $approvals instanceof DatabaseGuardianApprovals ? $approvals->expireDue() : 0;
        $this->info("Expired {$count} approval request(s).");

        return self::SUCCESS;
    }
}
