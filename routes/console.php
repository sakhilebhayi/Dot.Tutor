<?php

use App\Console\Commands\PublishMetrics;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('tutor:expire-stale-sessions')->dailyAt('02:00');

// Daily aggregate snapshot for Dot.Analytics, after the stale-session sweep above. Queued per company; sends
// nothing until ANALYTICS_INGEST_URL and ANALYTICS_INGEST_SECRET are set. Needs a queue worker.
Schedule::command(PublishMetrics::class)
    ->dailyAt('03:00')
    ->timezone('UTC')
    ->withoutOverlapping()
    ->onOneServer();
