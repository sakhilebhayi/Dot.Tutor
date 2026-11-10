<?php

namespace App\Domain\Money\Console;

use App\Domain\Money\Services\Payouts;
use Illuminate\Console\Command;

class MoneyDraftPayouts extends Command
{
    protected $signature = 'money:draft-payouts {--force : Draft even when today is not the payout day}';

    protected $description = 'Draft the payout batch (available earnings up to the end of yesterday) on the configured payout day';

    public function handle(Payouts $payouts): int
    {
        if (! $this->option('force') && now()->dayOfWeekIso !== (int) config('money.payouts.schedule_day', 1)) {
            $this->line('Not the payout day.');

            return self::SUCCESS;
        }

        $batch = $payouts->draftBatch(now()->subDay()->endOfDay(), null);
        $this->line("Batch #{$batch->id}: {$batch->payout_count} payout(s).");

        return self::SUCCESS;
    }
}
