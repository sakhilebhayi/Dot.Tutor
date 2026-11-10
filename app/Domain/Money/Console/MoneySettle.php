<?php

namespace App\Domain\Money\Console;

use App\Domain\Money\Services\Sweep;
use Illuminate\Console\Command;

class MoneySettle extends Command
{
    protected $signature = 'money:settle';

    protected $description = 'Settlement sweep: verify open checkouts, process stored webhooks, expire unpaid orders, release due earnings, retry refunds and transfers';

    public function handle(Sweep $sweep): int
    {
        foreach ($sweep->run() as $what => $count) {
            $this->line(str_pad($what, 20).' '.$count);
        }

        return self::SUCCESS;
    }
}
