<?php

namespace App\Domain\Comms\Console;

use App\Domain\Comms\Reminders;
use Illuminate\Console\Command;

/** Every five minutes: lesson reminders (24 h and 1 h before) and assignment due reminders. Idempotent. */
class RemindersCommand extends Command
{
    protected $signature = 'comms:reminders';

    protected $description = 'Send lesson and assignment reminders that are due (safe to run repeatedly)';

    public function handle(Reminders $reminders): int
    {
        $result = $reminders->run();
        $this->info("Wrote {$result['lessons']} lesson reminders and {$result['assignments']} assignment reminders.");

        return self::SUCCESS;
    }
}
