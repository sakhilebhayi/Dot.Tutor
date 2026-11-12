<?php

namespace App\Domain\Marketplace\Console;

use App\Domain\Marketplace\Services\BookingFlow;
use Illuminate\Console\Command;

/**
 * Releases holds that ran out: group seats and accepted bookings whose
 * payment window passed, requests still waiting for a guardian when the
 * lesson starts, and packages past their validity. Runs from the cron-driven
 * scheduler every five minutes.
 */
class ExpireMarketplaceHolds extends Command
{
    protected $signature = 'tutor:expire-marketplace-holds';

    protected $description = 'Release expired payment and guardian holds on marketplace bookings, seats and packages.';

    public function handle(BookingFlow $flow): int
    {
        $counts = $flow->expireHolds();
        $this->info("Released {$counts['seats']} seat(s), cancelled {$counts['bookings']} booking(s), expired {$counts['packages']} package(s).");

        return self::SUCCESS;
    }
}
