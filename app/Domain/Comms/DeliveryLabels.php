<?php

namespace App\Domain\Comms;

use App\Domain\Comms\Models\NotificationDelivery;

/** Plain words for a delivery's state; "sent" only when the channel accepted it. */
final class DeliveryLabels
{
    private const CHANNELS = ['in_app' => 'In the app', 'push' => 'Push', 'email' => 'Email', 'sms' => 'SMS', 'notify' => 'Dot.Notify'];

    private const REASONS = [
        'preference_off' => 'off in your settings',
        'own_action' => 'not sent (you did this yourself)',
        'push_not_configured' => 'not set up on Dot.Tutor yet',
        'no_push_subscription' => 'not turned on for any of your devices',
        'subscription_gone' => 'the device no longer accepts it',
        'no_email_address' => 'no email address on your account',
        'mail_transport_not_delivering' => 'not delivered (Dot.Tutor only logs email at the moment)',
        'sms_not_configured' => 'not available on Dot.Tutor',
        'child_in_app_only' => 'not sent: children get notices in the app only, and a parent or guardian gets a copy',
        'superseded' => 'not sent (a newer update replaced it)',
        'expired' => 'not sent (too late to be useful)',
        'recipient_gone' => 'account no longer exists',
        'notification_gone' => 'the message was deleted',
        'quiet_hours' => 'waiting until your quiet hours end',
        'no_company' => 'not sent (no InfoDot company to route it to)',
        'notify_not_configured' => 'Dot.Notify is not connected',
    ];

    public static function channel(string $channel): string
    {
        return self::CHANNELS[$channel] ?? ucfirst($channel);
    }

    public static function reason(?string $reason): string
    {
        return $reason === null ? 'not sent' : (self::REASONS[$reason] ?? str_replace('_', ' ', $reason));
    }

    public static function for(NotificationDelivery $delivery): string
    {
        $channel = self::channel($delivery->channel);

        return match ($delivery->status) {
            NotificationDelivery::SENT => match ($delivery->channel) {
                NotificationDelivery::IN_APP => $channel,
                NotificationDelivery::NOTIFY => "{$channel}: handed over".(($delivery->meta['notify_status'] ?? null) === 'no_rule' ? ' (no rule there, so nothing was sent)' : ''),
                default => "{$channel}: sent",
            },
            NotificationDelivery::QUEUED, NotificationDelivery::SENDING => $delivery->reason === 'quiet_hours' && $delivery->attempts === 0
                ? "{$channel}: ".self::REASONS['quiet_hours']
                : ($delivery->attempts > 0 ? "{$channel}: retrying" : "{$channel}: waiting to send"),
            NotificationDelivery::FAILED => "{$channel}: failed".($delivery->reason ? ' ('.self::reason($delivery->reason).')' : ''),
            default => "{$channel}: ".self::reason($delivery->reason),
        };
    }
}
