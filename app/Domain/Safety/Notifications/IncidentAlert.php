<?php

namespace App\Domain\Safety\Notifications;

use App\Domain\Safety\Models\Incident;
use Illuminate\Notifications\Notification;

/** Database notice to operators when an incident opens or its response timer runs out. */
class IncidentAlert extends Notification
{
    public function __construct(public readonly Incident $incident, public readonly string $event) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'incident_id' => $this->incident->id,
            'severity' => $this->incident->severity,
            'event' => $this->event,
            'message' => $this->event === 'escalated'
                ? 'Incident '.$this->incident->reference.' has not been acknowledged and was escalated to level '.$this->incident->escalation_level.'.'
                : 'New '.$this->incident->severity.' safeguarding incident '.$this->incident->reference.'.',
            'url' => route('safety.operator.incidents.show', $this->incident),
        ];
    }
}
