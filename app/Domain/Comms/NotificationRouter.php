<?php

namespace App\Domain\Comms;

use App\Domain\Comms\Contracts\MinorRouting;
use App\Domain\Comms\Models\NotificationDelivery;
use App\Domain\Comms\Notifications\CommsNotification;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Writes a comms notification: the in-app record first (exactly once per event key and person, so a retried or
 * doubled domain event never shows twice), then FanOut decides the push, email, SMS and Dot.Notify copies. Never
 * throws into the caller: a notification that cannot be written is logged, and the action that caused it stands.
 * Marketing-style messages are refused outright for a minor (no record at all).
 */
final class NotificationRouter
{
    public function __construct(
        private readonly CommsCategories $categories,
        private readonly FanOut $fanOut,
        private readonly MinorRouting $minors,
    ) {}

    /** @return string|null the in-app notification id; null when it was a duplicate, refused or failed */
    public function send(CommsMessage $message): ?string
    {
        try {
            $category = $this->categories->categoryFor($message->type);

            if ($this->categories->isMarketing($category) && $this->minors->isMinor($message->recipient)) {
                return null;
            }

            return DB::transaction(fn () => $this->write($message, $category), 3);
        } catch (Throwable $e) {
            Log::warning('Could not write a notification.', ['type' => $message->type, 'user_id' => $message->recipient->id, 'error' => class_basename($e)]);

            return null;
        }
    }

    private function write(CommsMessage $message, string $category): ?string
    {
        $now = now();

        $inserted = DB::table('comms_deliveries')->insertOrIgnore([
            'event_key' => $message->eventKey,
            'user_id' => $message->recipient->id,
            'channel' => NotificationDelivery::IN_APP,
            'category' => $category,
            'type' => $message->type,
            'status' => NotificationDelivery::SENT,
            'collapse_key' => $message->collapseKey,
            'sent_at' => $now,
            'attempts' => 1,
            'last_attempt_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($inserted === 0) {
            return null; // this event already reached this person
        }

        if ($message->replaceUnread && $message->collapseKey !== null) {
            $this->removeUnreadWithCollapseKey($message);
        }

        $notification = new CommsNotification($message, $category);
        $notification->id = (string) Str::uuid();
        $message->recipient->notifyNow($notification, ['database']);

        NotificationDelivery::where('event_key', $message->eventKey)->where('user_id', $message->recipient->id)
            ->where('channel', NotificationDelivery::IN_APP)
            ->update(['notification_id' => $notification->id, 'provider' => 'database', 'provider_id' => $notification->id]);

        $this->fanOut->plan($message->recipient, $notification->id, $message->type, $message->eventKey, $message->collapseKey,
            $message->actorIsRecipient(), $message->aboutMinor);

        return $notification->id;
    }

    private function removeUnreadWithCollapseKey(CommsMessage $message): void
    {
        $ids = NotificationDelivery::where('user_id', $message->recipient->id)
            ->where('collapse_key', $message->collapseKey)
            ->where('channel', NotificationDelivery::IN_APP)
            ->where('event_key', '!=', $message->eventKey)
            ->whereNotNull('notification_id')
            ->pluck('notification_id');

        if ($ids->isNotEmpty()) {
            DatabaseNotification::query()->whereIn('id', $ids)->whereNull('read_at')->delete();
        }
    }
}
