<?php

namespace App\Domain\Ai\Console;

use App\Domain\Ai\Models\AiInteraction;
use App\Domain\Safety\Audit;
use Illuminate\Console\Command;

/**
 * Retention for the AI safeguarding log: rows past purge_after are deleted (reported or flagged rows carry a longer
 * purge_after). Accepted question drafts keep their learning Question; the draft only loses its interaction link.
 */
class PurgeAiInteractions extends Command
{
    protected $signature = 'ai:purge-interactions';

    protected $description = 'Delete AI assistant log rows whose retention period has ended';

    public function handle(): int
    {
        $deleted = 0;
        AiInteraction::query()->due()->select('id')->chunkById(500, function ($rows) use (&$deleted) {
            $deleted += AiInteraction::query()->whereIn('id', $rows->pluck('id'))->delete();
        });

        if ($deleted > 0) {
            Audit::record(null, 'ai.interactions.purged', null, ['count' => $deleted]);
        }
        $this->info("Deleted {$deleted} AI interaction(s) past retention.");

        return self::SUCCESS;
    }
}
