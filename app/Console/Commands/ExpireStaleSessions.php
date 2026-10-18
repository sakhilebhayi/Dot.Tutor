<?php

namespace App\Console\Commands;

use App\Enums\SessionStatus;
use App\Models\TutorSession;
use Illuminate\Console\Command;

/**
 * Marks a pending session as a no-show once its scheduled end time
 * (starts_at + duration_minutes, via TutorSession::endsAt()) has passed.
 * Pure internal bookkeeping -- no notification, no money movement, no
 * approval step. Intended to run daily (see routes/console.php). Only
 * 'pending' sessions are eligible: a session that was already confirmed,
 * completed, or cancelled is left exactly as it is.
 */
class ExpireStaleSessions extends Command
{
    protected $signature = 'tutor:expire-stale-sessions';

    protected $description = 'Mark pending tutor sessions whose scheduled time has passed as no-show.';

    public function handle(): int
    {
        $expired = 0;

        TutorSession::query()
            ->where('status', SessionStatus::Pending->value)
            ->chunkById(100, function ($sessions) use (&$expired) {
                foreach ($sessions as $session) {
                    if ($session->endsAt()->isPast()) {
                        $session->update(['status' => SessionStatus::NoShow->value]);
                        $expired++;
                    }
                }
            });

        $this->info("Marked {$expired} stale session(s) as no-show.");

        return self::SUCCESS;
    }
}
