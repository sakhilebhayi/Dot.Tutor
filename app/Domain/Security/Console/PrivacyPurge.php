<?php

namespace App\Domain\Security\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The retention rules that are not some other domain's job (docs/R47_PRIVACY.md section 5). Daily at 03:50 UTC.
 * Other domains purge their own: ai:purge-interactions (AI prompts), comms:prune (delivery records).
 */
class PrivacyPurge extends Command
{
    protected $signature = 'tutor:privacy-purge {--dry-run : Count what would go, delete nothing}';

    protected $description = 'Delete personal information whose retention period has ended (R-47 privacy).';

    public function handle(): int
    {
        $rules = [
            // A guardian invitation names a third party by email; once it can no longer be used it serves nobody.
            'unused guardian invitations (30 days after expiry)' => fn () => DB::table('safety_guardian_invitations')
                ->whereNull('accepted_at')->where('expires_at', '<', now()->subDays(30)),
            // WebRTC signalling carries network addresses (ICE candidates); it is only needed while connecting.
            'classroom signalling (older than 1 day)' => fn () => DB::table('classroom_signals')->where('created_at', '<', now()->subDay()),
            'password reset tokens (older than 1 day)' => fn () => DB::table('password_reset_tokens')->where('created_at', '<', now()->subDay()),
            // Failed job payloads can hold names, emails and message text.
            'failed jobs (older than 30 days)' => fn () => DB::table('failed_jobs')->where('failed_at', '<', now()->subDays(30)),
        ];

        $tables = ['safety_guardian_invitations', 'classroom_signals', 'password_reset_tokens', 'failed_jobs'];
        $i = 0;

        foreach ($rules as $label => $query) {
            if (! Schema::hasTable($tables[$i++])) {
                continue;
            }

            $count = $this->option('dry-run') ? $query()->count() : $query()->delete();
            $this->line(sprintf('%s: %d %s', $label, $count, $this->option('dry-run') ? 'would go' : 'deleted'));
        }

        return self::SUCCESS;
    }
}
