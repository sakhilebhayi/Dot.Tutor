<?php

namespace App\Domain\Safety\Notifications;

use Illuminate\Notifications\Notification;

class AccountWarned extends Notification
{
    public function __construct(public readonly string $kind, public readonly string $reason) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'decision' => $this->kind,
            'reason' => $this->reason,
            'message' => $this->kind === 'suspension'
                ? 'Your account was suspended by Dot.Tutor safeguarding.'
                : 'You received a warning from Dot.Tutor moderation.',
        ];
    }
}
