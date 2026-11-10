<?php

namespace App\Domain\Comms\Listeners;

use App\Domain\Comms\CommsCategories;
use App\Domain\Comms\CommsMessage;
use App\Domain\Comms\FanOut;
use App\Domain\Comms\Messenger;
use App\Domain\Comms\Models\NotificationDelivery;
use App\Domain\Comms\Notifications\CommsNotification;
use App\Models\TutorSession;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Brings the in-app notifications other domains already write with $user->notify() (tutor application decisions,
 * moderation cancellations, guardian approvals, account warnings, safeguarding incident alerts) under the same rules
 * as comms' own: their record gains a type, category, title and subject key (so the inbox and the unread count treat
 * them alike), one in_app delivery row, then FanOut makes the push and email copies the person's choices allow.
 * A source that emails by itself (guardian approval requests) has that email recorded instead of a second copy.
 * A minor's account warning or cancelled lesson is copied to their guardians. Never throws.
 */
final class SourceNotificationListener
{
    private const TITLES = [
        'tutor_application_decided' => 'Your tutor application',
        'lesson_cancelled' => 'Lesson request cancelled',
        'guardian_approval_requested' => 'Approval needed',
        'guardian_approval_decided' => 'Your guardian answered',
        'account_warned' => 'Message from Dot.Tutor moderation',
        'incident_alert' => 'Safeguarding incident',
    ];

    public function __construct(
        private readonly FanOut $fanOut,
        private readonly CommsCategories $categories,
        private readonly Messenger $messenger,
    ) {}

    public function sent(NotificationSent $event): void
    {
        try {
            if (! $event->notifiable instanceof User || $event->notification instanceof CommsNotification) {
                return;
            }

            $type = $this->typeOf($event->notification);

            if ($type === null) {
                return;
            }

            if ($event->channel === 'database' && $event->response instanceof DatabaseNotification) {
                $this->fromDatabase($event->notifiable, $event->response, $event->notification, $type);

                return;
            }

            if ($event->channel === 'mail') {
                $mailer = (string) config('mail.default');
                $delivers = ! in_array($mailer, ['log', 'array'], true);
                $this->recordMail($event->notifiable, $event->notification, $type, $delivers ? NotificationDelivery::SENT : NotificationDelivery::SKIPPED,
                    $delivers ? null : 'mail_transport_not_delivering', 'mail:'.$mailer);
            }
        } catch (Throwable $e) {
            Log::warning('Comms could not fan out a notification.', ['notification' => class_basename($event->notification), 'error' => class_basename($e)]);
        }
    }

    public function failed(NotificationFailed $event): void
    {
        try {
            $type = $this->typeOf($event->notification);
            if ($event->notifiable instanceof User && $event->channel === 'mail' && $type !== null) {
                $this->recordMail($event->notifiable, $event->notification, $type, NotificationDelivery::FAILED, 'mail_error', 'mail:'.config('mail.default'));
            }
        } catch (Throwable) {
        }
    }

    private function fromDatabase(User $user, DatabaseNotification $record, object $notification, string $type): void
    {
        $data = (array) $record->data;
        $category = $this->categories->categoryFor($type);
        $collapse = match (true) {
            isset($data['approval_id']) => 'approval-'.(int) $data['approval_id'],
            isset($data['session_id']) => 'booking-'.(int) $data['session_id'],
            isset($data['incident_id']) => 'incident-'.(int) $data['incident_id'],
            isset($data['tutor_profile_id']) => 'tutor-profile-'.(int) $data['tutor_profile_id'],
            default => null,
        };

        $record->forceFill(['data' => $data + array_filter([
            'type' => $type,
            'category' => $category,
            'title' => self::TITLES[$type] ?? $this->categories->label($category),
            'collapse_key' => $collapse,
        ])])->save();

        $now = now();
        $eventKey = 'n:'.$record->id;
        DB::table('comms_deliveries')->insertOrIgnore([
            'event_key' => $eventKey,
            'user_id' => $user->id,
            'channel' => NotificationDelivery::IN_APP,
            'category' => $category,
            'type' => $type,
            'notification_id' => $record->id,
            'status' => NotificationDelivery::SENT,
            'provider' => 'database',
            'provider_id' => $record->id,
            'collapse_key' => $collapse,
            'attempts' => 1,
            'last_attempt_at' => $now,
            'sent_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $ownMail = in_array($notification::class, (array) config('comms.source_sends_own_email', []), true);
        $this->fanOut->plan($user, (string) $record->id, $type, $eventKey, $collapse, false, $this->messenger->isMinor($user), $ownMail);

        // A minor's account warning or moderation-cancelled lesson: their guardians hear too.
        if (in_array($type, ['account_warned', 'lesson_cancelled'], true) && $this->messenger->isMinor($user)) {
            $when = isset($data['session_id']) ? TutorSession::query()->find((int) $data['session_id'])?->starts_at : null;
            $this->messenger->learnerAndGuardians($user, new CommsMessage($user, $type, $eventKey, '', '', null, $collapse), fn (User $g) => $type === 'account_warned'
                ? ['Dot.Tutor moderation contacted '.$this->messenger->firstName($user), (string) ($data['message'] ?? '')]
                : [$this->messenger->firstName($user).'\'s lesson request was cancelled', 'The tutor is no longer taking bookings'.($when ? ', so the lesson asked for on '.$this->messenger->when($when, $g).' will not take place.' : '.')],
                toLearner: false);
        }
    }

    private function recordMail(User $user, object $notification, string $type, string $status, ?string $reason, string $provider): void
    {
        $now = now();
        DB::table('comms_deliveries')->insertOrIgnore([
            'event_key' => 'n:'.($notification->id ?? sha1(spl_object_hash($notification))),
            'user_id' => $user->id,
            'channel' => NotificationDelivery::EMAIL,
            'category' => $this->categories->categoryFor($type),
            'type' => $type,
            'notification_id' => $notification->id ?? null,
            'status' => $status,
            'reason' => $reason,
            'provider' => $provider,
            'attempts' => 1,
            'last_attempt_at' => $now,
            'sent_at' => $status === NotificationDelivery::SENT ? $now : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function typeOf(object $notification): ?string
    {
        $type = ((array) config('comms.source_types', []))[$notification::class] ?? null;

        return is_string($type) ? $type : null;
    }
}
