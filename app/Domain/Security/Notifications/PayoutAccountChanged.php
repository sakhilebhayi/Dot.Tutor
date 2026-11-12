<?php

namespace App\Domain\Security\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells a tutor their payout bank account changed (R-47 security S-13), so a change they did not make is noticed. */
class PayoutAccountChanged extends Notification
{
    public function __construct(public readonly string $last4, public readonly int $holdHours) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Dot.Tutor payout bank account was changed')
            ->line('The bank account your earnings are paid into was changed to an account ending in '.$this->last4.'.')
            ->line('For your protection, payouts to the new account start after '.$this->holdHours.' hours, and any payout that was being prepared waits for the next batch.')
            ->line('If you did not make this change, contact support straight away and sign out of every device.');
    }
}
