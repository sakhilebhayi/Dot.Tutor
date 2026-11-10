<?php

namespace App\Domain\Safety\Notifications;

use App\Domain\Safety\Models\GuardianApproval;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class GuardianApprovalRequested extends Notification
{
    public function __construct(public readonly GuardianApproval $approval, public readonly string $learnerName) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->learnerName.' is waiting for your approval')
            ->line($this->learnerName.' asked for approval: '.($this->approval->summary ?? str_replace('_', ' ', $this->approval->kind)).'.')
            ->action('Approve or decline', route('safety.guardian.dashboard'))
            ->line('The request lapses on its own if nobody decides in time.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'approval_id' => $this->approval->id,
            'kind' => $this->approval->kind,
            'message' => $this->learnerName.' is waiting for your approval ('.str_replace('_', ' ', $this->approval->kind).').',
            'url' => route('safety.guardian.dashboard'),
        ];
    }
}
