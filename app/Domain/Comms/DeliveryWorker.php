<?php

namespace App\Domain\Comms;

use App\Domain\Comms\Calendar\LessonCalendar;
use App\Domain\Comms\Contracts\MinorRouting;
use App\Domain\Comms\Mail\CommsMail;
use App\Domain\Comms\Models\NotificationDelivery;
use App\Domain\Comms\Notify\NotifyClient;
use App\Domain\Comms\Push\PushResult;
use App\Domain\Comms\Push\PushSubscriptions;
use App\Domain\Comms\Push\WebPushClient;
use App\Domain\Comms\Sms\SmsSender;
use App\Models\TutorSession;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends queued deliveries (adapted from Dot.Ehail R-46). Each row is claimed with a compare-and-swap (queued ->
 * sending, with a lease), so two workers never send the same copy; a lease that runs out puts the row back.
 * Outcomes are recorded as they really were: `sent` only when the channel accepted the message, `queued` again with
 * a backoff for temporary failures, `failed` after the last attempt, `skipped` with the reason when sending would be
 * pointless (a newer update overtook a push, the lesson already started, email only logs).
 */
final class DeliveryWorker
{
    public function __construct(
        private readonly WebPushClient $push,
        private readonly PushSubscriptions $subscriptions,
        private readonly SmsSender $sms,
        private readonly NotifyClient $notify,
        private readonly ChannelStatus $status,
        private readonly CommsCategories $categories,
        private readonly LessonCalendar $calendar,
        private readonly MinorRouting $minors,
    ) {}

    /** @param list<int> $ids */
    public function deliverIds(array $ids): void
    {
        foreach ($ids as $id) {
            $this->process((int) $id);
        }
    }

    /** @return int how many deliveries were attempted */
    public function deliverDue(?int $limit = null): int
    {
        NotificationDelivery::where('status', NotificationDelivery::SENDING)
            ->where('claimed_until', '<', now())
            ->update(['status' => NotificationDelivery::QUEUED, 'claimed_until' => null, 'updated_at' => now()]);

        $ids = NotificationDelivery::where('status', NotificationDelivery::QUEUED)
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('id')
            ->limit($limit ?? (int) config('comms.delivery.batch', 100))
            ->pluck('id')->all();

        $this->deliverIds($ids);

        return count($ids);
    }

    public function process(int $id): void
    {
        $claimed = NotificationDelivery::whereKey($id)
            ->where('status', NotificationDelivery::QUEUED)
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->update([
                'status' => NotificationDelivery::SENDING,
                'claimed_until' => now()->addSeconds((int) config('comms.delivery.lease_seconds', 120)),
                'attempts' => DB::raw('attempts + 1'),
                'last_attempt_at' => now(),
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            return; // someone else has it, or it is not due
        }

        $delivery = NotificationDelivery::find($id);

        try {
            $this->send($delivery);
        } catch (Throwable $e) {
            Log::warning('Comms delivery crashed; will retry.', ['delivery_id' => $id, 'channel' => $delivery->channel, 'error' => class_basename($e)]);
            $this->retryOrFail($delivery, 'worker_error');
        }
    }

    private function send(NotificationDelivery $delivery): void
    {
        $user = User::find($delivery->user_id);

        if ($user === null) {
            $this->finish($delivery, NotificationDelivery::SKIPPED, 'recipient_gone');

            return;
        }

        $record = $delivery->notification_id ? DatabaseNotification::find($delivery->notification_id) : null;

        if ($record === null) {
            $this->finish($delivery, NotificationDelivery::SKIPPED, 'notification_gone');

            return;
        }

        $data = (array) $record->data;
        $title = (string) ($data['title'] ?? 'Dot.Tutor');
        $body = (string) ($data['message'] ?? '');
        $url = Inbox::localUrl(isset($data['url']) ? (string) $data['url'] : null);

        // A reminder for a lesson that has started, or anything past its stated expiry, is no longer useful.
        if ($delivery->channel !== NotificationDelivery::NOTIFY && isset($data['expires_at']) && Carbon::parse((string) $data['expires_at'])->isPast()) {
            $this->finish($delivery, NotificationDelivery::SKIPPED, 'expired');

            return;
        }

        match ($delivery->channel) {
            NotificationDelivery::PUSH => $this->sendPush($delivery, $user, $title, $body, $url),
            NotificationDelivery::EMAIL => $this->sendEmail($delivery, $user, $title, $body, $url, $data),
            NotificationDelivery::SMS => $this->sendSms($delivery, $title, $body),
            NotificationDelivery::NOTIFY => $this->sendNotify($delivery, $user, $title, $url, $record),
            default => $this->finish($delivery, NotificationDelivery::SKIPPED, 'unknown_channel'),
        };
    }

    private function sendPush(NotificationDelivery $delivery, User $user, string $title, string $body, ?string $url): void
    {
        if ($this->overtaken($delivery)) {
            $this->finish($delivery, NotificationDelivery::SKIPPED, 'superseded');

            return;
        }

        $subscriptions = $this->subscriptions->active($user);

        if ($subscriptions->isEmpty()) {
            $this->finish($delivery, NotificationDelivery::SKIPPED, 'no_push_subscription');

            return;
        }

        $meta = (array) ($delivery->meta ?? []);
        $accepted = array_map('intval', (array) ($meta['accepted'] ?? []));
        $counts = ['accepted' => count($accepted), 'gone' => 0, 'failed' => 0, 'retry' => 0];
        $providerId = $delivery->provider_id;
        $lastReason = null;
        $urgent = $this->categories->isUrgent($delivery->category);

        $payload = [
            'title' => $title,
            'body' => mb_substr($body, 0, 240),
            'url' => $url ?? '/comms/inbox',
            'tag' => $delivery->collapse_key ?? 'n-'.$delivery->notification_id,
            'critical' => $urgent,
        ];

        foreach ($subscriptions as $subscription) {
            if (in_array($subscription->id, $accepted, true)) {
                continue;
            }

            $result = $this->push->send($subscription, $payload, $urgent ? 'high' : 'normal', $delivery->collapse_key);

            switch ($result->status) {
                case PushResult::SENT:
                    $accepted[] = $subscription->id;
                    $counts['accepted']++;
                    $providerId ??= $result->providerId;
                    $subscription->forceFill(['last_success_at' => now(), 'failure_count' => 0])->save();
                    break;
                case PushResult::GONE:
                    $counts['gone']++;
                    $this->subscriptions->revoke($subscription, (string) $result->reason);
                    break;
                case PushResult::RETRY:
                    $counts['retry']++;
                    $lastReason = $result->reason;
                    $subscription->forceFill(['last_failure_at' => now(), 'failure_count' => $subscription->failure_count + 1])->save();
                    break;
                default:
                    $counts['failed']++;
                    $lastReason = $result->reason;
                    $subscription->forceFill(['last_failure_at' => now(), 'failure_count' => $subscription->failure_count + 1])->save();
            }
        }

        $delivery->forceFill(['meta' => ['accepted' => $accepted] + $counts, 'provider' => 'webpush', 'provider_id' => $providerId]);

        match (true) {
            $counts['retry'] > 0 => $this->retryOrFail($delivery, (string) $lastReason, $counts['accepted'] > 0),
            $counts['accepted'] > 0 => $this->finish($delivery, NotificationDelivery::SENT),
            $counts['failed'] > 0 => $this->finish($delivery, NotificationDelivery::FAILED, (string) $lastReason),
            default => $this->finish($delivery, NotificationDelivery::FAILED, 'subscription_gone'),
        };
    }

    /** @param array<string, mixed> $data */
    private function sendEmail(NotificationDelivery $delivery, User $user, string $title, string $body, ?string $url, array $data): void
    {
        if (trim((string) $user->email) === '') {
            $this->finish($delivery, NotificationDelivery::SKIPPED, 'no_email_address');

            return;
        }

        if (! $this->status->mailDelivers()) {
            $this->finish($delivery, NotificationDelivery::SKIPPED, 'mail_transport_not_delivering');

            return;
        }

        [$ics, $icsName] = [null, null];
        if (isset($data['ics_session_id']) && ($session = TutorSession::find((int) $data['ics_session_id'])) !== null) {
            $ics = $this->calendar->forSession($session, fn (User $u) => $this->minors->isMinor($u));
            $icsName = $this->calendar->filename($session);
        }

        try {
            $sent = Mail::to($user->email)->send(new CommsMail(
                $title,
                $body,
                $url !== null ? url($url) : null,
                isset($data['action_label']) ? (string) $data['action_label'] : null,
                $this->categories->label($delivery->category),
                $this->categories->isMandatory($delivery->category),
                route('comms.preferences'),
                $ics,
                $icsName,
            ));
        } catch (Throwable $e) {
            $this->retryOrFail($delivery, 'mail_error_'.strtolower(class_basename($e)));

            return;
        }

        $delivery->forceFill([
            'provider' => 'mail:'.$this->status->mailer(),
            'provider_id' => $sent !== null ? mb_substr((string) $sent->getMessageId(), 0, 191) : null,
            'meta' => $ics !== null ? ['calendar_attached' => true] : null,
        ]);
        $this->finish($delivery, NotificationDelivery::SENT);
    }

    private function sendSms(NotificationDelivery $delivery, string $title, string $body): void
    {
        // No number collection exists (decision D-1006), so even a configured driver has nobody to text.
        $this->finish($delivery, NotificationDelivery::SKIPPED, $this->sms->configured() ? 'no_confirmed_number' : 'sms_not_configured');
    }

    private function sendNotify(NotificationDelivery $delivery, User $user, string $title, ?string $url, DatabaseNotification $record): void
    {
        $event = $this->notify->eventFor($delivery->type);

        if ($event === null || ! $this->notify->enabled()) {
            $this->finish($delivery, NotificationDelivery::SKIPPED, 'notify_not_configured');

            return;
        }

        $payload = $this->notify->payload($user, $event, $delivery->event_key, array_filter([
            'title' => mb_substr($title, 0, 200),
            'url' => $url !== null ? preg_replace('#^http://#', 'https://', url($url)) : null,
            'occurred_at' => $record->created_at?->toIso8601String(),
        ], fn ($v) => $v !== null));

        if ($payload === null) {
            $this->finish($delivery, NotificationDelivery::SKIPPED, 'no_company');

            return;
        }

        $result = $this->notify->deliver($payload);
        $delivery->forceFill(['provider' => 'dot-notify', 'meta' => ['http' => $result['http'], 'notify_status' => $result['notify_status']]]);

        match ($result['outcome']) {
            NotifyClient::DONE => $this->finish($delivery, NotificationDelivery::SENT),
            NotifyClient::RETRY => $this->retryOrFail($delivery, (string) $result['notify_status']),
            default => $this->finish($delivery, NotificationDelivery::FAILED, (string) $result['notify_status']),
        };
    }

    /** A newer push about the same thing (same collapse key) exists for this person: this one is stale. */
    private function overtaken(NotificationDelivery $delivery): bool
    {
        if ($delivery->collapse_key === null) {
            return false;
        }

        return NotificationDelivery::where('user_id', $delivery->user_id)
            ->where('channel', NotificationDelivery::PUSH)
            ->where('collapse_key', $delivery->collapse_key)
            ->where('id', '>', $delivery->id)
            ->whereIn('status', [NotificationDelivery::QUEUED, NotificationDelivery::SENDING, NotificationDelivery::SENT])
            ->exists();
    }

    private function retryOrFail(NotificationDelivery $delivery, string $reason, bool $partlySent = false): void
    {
        $backoff = (array) config('comms.delivery.backoff', [60, 300, 900, 3600]);
        $max = (int) config('comms.delivery.max_attempts', 5);

        if ($delivery->attempts >= $max) {
            $this->finish($delivery, $partlySent ? NotificationDelivery::SENT : NotificationDelivery::FAILED, $reason);

            return;
        }

        $wait = (int) ($backoff[min($delivery->attempts - 1, count($backoff) - 1)] ?? 300);
        $delivery->forceFill([
            'status' => NotificationDelivery::QUEUED,
            'reason' => mb_substr($reason, 0, 160),
            'next_attempt_at' => now()->addSeconds($wait),
            'claimed_until' => null,
            'sent_at' => $partlySent ? ($delivery->sent_at ?? now()) : $delivery->sent_at,
        ])->save();
    }

    private function finish(NotificationDelivery $delivery, string $status, ?string $reason = null): void
    {
        $delivery->forceFill([
            'status' => $status,
            'reason' => $reason !== null ? mb_substr($reason, 0, 160) : null,
            'claimed_until' => null,
            'next_attempt_at' => null,
            'sent_at' => $status === NotificationDelivery::SENT ? ($delivery->sent_at ?? now()) : $delivery->sent_at,
        ])->save();
    }
}
