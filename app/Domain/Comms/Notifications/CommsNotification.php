<?php

namespace App\Domain\Comms\Notifications;

use App\Domain\Comms\CommsMessage;
use Illuminate\Notifications\Notification;

/**
 * The in-app record of a comms notification (database channel only). Same keys as the app's other in-app
 * notifications (message, url) plus type, category and title, so the dashboard list and the inbox both render it.
 * Push, email, SMS and Dot.Notify copies are made by FanOut from this record.
 */
class CommsNotification extends Notification
{
    public function __construct(public CommsMessage $message, public string $category) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return array_filter([
            'type' => $this->message->type,
            'category' => $this->category,
            'title' => $this->message->title,
            'message' => $this->message->body,
            'url' => $this->message->url,
            'collapse_key' => $this->message->collapseKey,
            'about_minor' => $this->message->aboutMinor ?: null,
        ], fn ($value) => $value !== null) + $this->message->data;
    }
}
