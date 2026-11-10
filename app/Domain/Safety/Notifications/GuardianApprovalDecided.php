<?php

namespace App\Domain\Safety\Notifications;

use App\Domain\Safety\Models\GuardianApproval;
use Illuminate\Notifications\Notification;

class GuardianApprovalDecided extends Notification
{
    public function __construct(public readonly GuardianApproval $approval) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $approved = $this->approval->status === GuardianApproval::APPROVED;

        return [
            'approval_id' => $this->approval->id,
            'kind' => $this->approval->kind,
            'decision' => $this->approval->status,
            'message' => $approved
                ? 'Your guardian approved your request ('.str_replace('_', ' ', $this->approval->kind).').'
                : 'Your guardian declined your request ('.str_replace('_', ' ', $this->approval->kind).').',
            'reason' => $this->approval->note,
        ];
    }
}
