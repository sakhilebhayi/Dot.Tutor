<?php

namespace App\Domain\Safety\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a learner's existing guardians that another adult was linked (R-47 security S-06): a learner chooses who
 * to invite, so the guardians already in place must hear about a new one and can ask support to remove it.
 */
class GuardianAdded extends Notification
{
    public function __construct(
        public readonly string $learnerFirstName,
        public readonly string $relationship,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Another guardian was linked to '.$this->learnerFirstName.' on Dot.Tutor')
            ->line('Another adult accepted an invitation from '.$this->learnerFirstName.' and is now linked as their '.str_replace('_', ' ', $this->relationship).'. They can see lessons, purchases and messages and approve bookings, as you can.')
            ->action('Review guardians', route('safety.guardian.dashboard'))
            ->line('If you do not recognise this person, report it from your guardian dashboard or contact support: an operator can remove the link.');
    }
}
