<?php

namespace App\Domain\Money\Jobs;

use App\Domain\Money\Models\WebhookEvent;
use App\Domain\Money\Services\FinanceIssues;
use App\Domain\Money\Webhooks\PaystackWebhookProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Processes one stored Paystack webhook (re-verifying with Paystack). Claimed atomically so two workers, or a
 * worker and the sweep, never process the same event together.
 */
class ProcessPaystackWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const MAX_ATTEMPTS = 5;

    public int $tries = self::MAX_ATTEMPTS;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 600, 1800];

    public function __construct(public int $eventId) {}

    public function handle(PaystackWebhookProcessor $processor): void
    {
        $claimed = DB::table('money_webhook_events')->where('id', $this->eventId)
            ->whereIn('status', ['received', 'failed'])
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->update(['status' => 'processing', 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);

        if ($claimed === 0) {
            return;
        }

        $event = WebhookEvent::findOrFail($this->eventId);

        try {
            $outcome = $processor->process($event);
        } catch (Throwable $e) {
            $event->forceFill(['status' => 'failed', 'outcome' => mb_substr(class_basename($e), 0, 160)])->save();
            $this->raiseIfExhausted($event);

            throw $e;
        }

        $event->forceFill([
            'status' => $outcome === 'ignored' ? 'ignored' : 'processed',
            'outcome' => mb_substr($outcome, 0, 160),
            'processed_at' => now(),
        ])->save();
    }

    private function raiseIfExhausted(WebhookEvent $event): void
    {
        if ($event->attempts >= self::MAX_ATTEMPTS) {
            app(FinanceIssues::class)->raise('webhook_failed', "webhook-failed:{$event->id}", "Paystack {$event->event} could not be processed", [
                'severity' => 'critical', 'subject_type' => 'webhook_event', 'subject_id' => $event->id, 'details' => ['reference' => $event->reference],
            ]);
        }
    }
}
