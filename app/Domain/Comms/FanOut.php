<?php

namespace App\Domain\Comms;

use App\Domain\Comms\Contracts\MinorRouting;
use App\Domain\Comms\Jobs\DeliverNotifications;
use App\Domain\Comms\Models\NotificationDelivery;
use App\Domain\Comms\Models\PushSubscription;
use App\Domain\Comms\Notify\NotifyClient;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Decides, for one in-app notification, which extra copies (push, email, SMS, Dot.Notify) to make, and writes one
 * comms_deliveries row per relevant channel: `queued` for the worker (possibly not before the end of quiet hours),
 * or `skipped` with the reason (preference off, own action, a child's account, provider not configured, no device).
 * Rows are idempotent per event, person and channel. Queued rows are handed to the worker after the surrounding
 * transaction commits: at once for urgent categories, through the queue otherwise. Adapted from Dot.Ehail R-46.
 */
final class FanOut
{
    public function __construct(
        private readonly CommsCategories $categories,
        private readonly Preferences $preferences,
        private readonly ChannelStatus $status,
        private readonly QuietHours $quiet,
        private readonly MinorRouting $minors,
        private readonly NotifyClient $notify,
    ) {}

    /**
     * @param  bool  $sourceSendsOwnEmail  the other domain's notification emails by itself (recorded separately)
     * @return list<int> ids of deliveries queued for now
     */
    public function plan(User $user, string $notificationId, string $type, string $eventKey, ?string $collapseKey, bool $actorIsRecipient, bool $aboutMinor = false, bool $sourceSendsOwnEmail = false): array
    {
        if ($this->categories->isInAppOnlyType($type)) {
            return [];
        }

        $category = $this->categories->categoryFor($type);
        $isChild = $this->minors->band($user) === 'child';
        $now = now();
        $queuedNow = [];

        $channels = $this->categories->channels($category);
        if ($this->notify->enabled() && $this->notify->eventFor($type) !== null) {
            $channels[] = NotificationDelivery::NOTIFY;
        }

        foreach ($channels as $channel) {
            if ($channel === NotificationDelivery::EMAIL && $sourceSendsOwnEmail) {
                continue; // SourceNotificationListener records the other domain's own email
            }

            $decision = $this->decide($user, $channel, $category, $actorIsRecipient, $isChild, $aboutMinor);

            if ($decision === false) {
                continue;
            }

            $deferUntil = null;
            if ($decision === true && in_array($channel, [NotificationDelivery::PUSH, NotificationDelivery::SMS], true) && ! $this->categories->isUrgent($category)) {
                $deferUntil = $this->quiet->deferUntil($user, $now);
            }

            DB::table('comms_deliveries')->insertOrIgnore([
                'event_key' => $eventKey,
                'user_id' => $user->id,
                'channel' => $channel,
                'category' => $category,
                'type' => $type,
                'notification_id' => $notificationId,
                'status' => $decision === true ? NotificationDelivery::QUEUED : NotificationDelivery::SKIPPED,
                'reason' => $decision === true ? ($deferUntil ? 'quiet_hours' : null) : $decision,
                'collapse_key' => $collapseKey,
                'next_attempt_at' => $decision === true ? ($deferUntil ?? $now) : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($decision === true && $deferUntil === null) {
                $id = NotificationDelivery::where('event_key', $eventKey)->where('user_id', $user->id)->where('channel', $channel)
                    ->where('status', NotificationDelivery::QUEUED)->value('id');
                if ($id !== null) {
                    $queuedNow[] = (int) $id;
                }
            }
        }

        if ($queuedNow !== []) {
            $this->handToWorker($queuedNow, $this->categories->isUrgent($category));
        }

        return $queuedNow;
    }

    /**
     * true: queue it. string: record it as skipped with this reason. false: the channel does not apply (no row).
     */
    private function decide(User $user, string $channel, string $category, bool $actorIsRecipient, bool $isChild, bool $aboutMinor): bool|string
    {
        if ($channel === NotificationDelivery::NOTIFY) {
            // Nothing about a minor, or addressed to one, leaves Dot.Tutor.
            if ($aboutMinor || $this->minors->isMinor($user) || $actorIsRecipient) {
                return false;
            }
            if (! $this->preferences->allows($user, $category, 'email')) {
                return 'preference_off';
            }

            return $this->notify->companyUuid($user) !== null ? true : 'no_company';
        }

        if ($channel === NotificationDelivery::SMS && ! $this->categories->isUrgent($category)) {
            return false;
        }

        if ($isChild) {
            return 'child_in_app_only';
        }

        if (! $this->preferences->allows($user, $category, $channel)) {
            return 'preference_off';
        }

        if ($actorIsRecipient) {
            return 'own_action';
        }

        return match ($channel) {
            NotificationDelivery::PUSH => $this->pushDecision($user),
            NotificationDelivery::EMAIL => $this->emailDecision($user),
            // No number collection exists yet (D-1006): even with a driver bound there is nobody's number to use.
            NotificationDelivery::SMS => $this->status->smsConfigured() ? 'no_confirmed_number' : 'sms_not_configured',
            default => false,
        };
    }

    private function pushDecision(User $user): bool|string
    {
        if (! $this->status->pushConfigured()) {
            return 'push_not_configured';
        }

        return PushSubscription::active()->where('user_id', $user->id)->exists() ? true : 'no_push_subscription';
    }

    private function emailDecision(User $user): bool|string
    {
        if (trim((string) $user->email) === '') {
            return 'no_email_address';
        }

        return $this->status->mailDelivers() ? true : 'mail_transport_not_delivering';
    }

    /** @param list<int> $ids */
    private function handToWorker(array $ids, bool $urgent): void
    {
        DB::afterCommit(function () use ($ids, $urgent) {
            try {
                match (true) {
                    $urgent && app()->runningInConsole() => DeliverNotifications::dispatchSync($ids),
                    $urgent => DeliverNotifications::dispatchAfterResponse($ids),
                    default => DeliverNotifications::dispatch($ids),
                };
            } catch (Throwable $e) {
                // The rows stay queued; the per-minute comms:deliver run picks them up.
                Log::warning('Could not hand deliveries to the worker; the scheduled run will.', ['count' => count($ids), 'error' => class_basename($e)]);
            }
        });
    }
}
