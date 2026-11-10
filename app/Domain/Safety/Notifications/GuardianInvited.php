<?php

namespace App\Domain\Safety\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/** Mail to an address that may not have an account yet (on-demand notifiable). */
class GuardianInvited extends Notification
{
    public function __construct(
        public readonly string $learnerName,
        public readonly string $relationship,
        public readonly string $url,
        public readonly Carbon $expiresAt,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->learnerName.' asked you to be their guardian on Dot.Tutor')
            ->line($this->learnerName.' uses Dot.Tutor for tutoring and learning and named you as their '.str_replace('_', ' ', $this->relationship).'.')
            ->line('Learners under 18 need a parent or guardian to link and give consent before they can book, pay or message tutors. You will be able to see their lessons, purchases and messages, and approve bookings.')
            ->action('Review and give consent', $this->url)
            ->line('You sign in with InfoDot. This link works until '.$this->expiresAt->timezone(config('tutor.timezone'))->format('j F Y, H:i').' and only for this email address.')
            ->line('If you do not know this learner, ignore this email.');
    }
}
