<?php

namespace App\Domain\Comms\Console;

use App\Domain\Comms\Push\Base64Url;
use App\Domain\Comms\Push\EcKeys;
use Illuminate\Console\Command;

/**
 * Prints a new VAPID key pair for .env. It writes nothing: keys are secrets and belong in the host's environment,
 * never in the repository. Changing keys later means every device turns push on again.
 */
class VapidKeysCommand extends Command
{
    protected $signature = 'comms:vapid-keys';

    protected $description = 'Generate a VAPID key pair for Web Push (prints the .env lines)';

    public function handle(): int
    {
        $keys = EcKeys::generate();

        $this->line('COMMS_VAPID_PUBLIC_KEY='.Base64Url::encode($keys['public']));
        $this->line('COMMS_VAPID_PRIVATE_KEY='.Base64Url::encode($keys['private']));
        $this->line('COMMS_VAPID_SUBJECT=mailto:no-reply@infodot.co.za');
        $this->newLine();
        $this->comment('Add these to the host .env (never commit them).');

        return self::SUCCESS;
    }
}
