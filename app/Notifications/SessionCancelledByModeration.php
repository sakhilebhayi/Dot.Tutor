<?php

namespace App\Notifications;

use App\Models\TutorSession;
use Illuminate\Notifications\Notification;

/** Told to a student whose pending request was cancelled because the tutor was suspended. */
class SessionCancelledByModeration extends Notification
{
    public function __construct(public readonly TutorSession $session) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'message' => 'Your session request for '.$this->session->starts_at->utc()->format('j M Y H:i').' UTC was cancelled because the tutor is no longer taking bookings.',
            'session_id' => $this->session->id,
            'url' => route('sessions.show', $this->session),
        ];
    }
}
