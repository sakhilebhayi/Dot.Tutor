<?php

namespace App\Domain\Comms;

use App\Domain\Comms\Notify\NotifyClient;
use App\Domain\Comms\Push\VapidKeys;
use App\Domain\Comms\Sms\SmsSender;

/**
 * What each channel can honestly do on this server right now. The preferences screen and the delivery worker both
 * read it, so the screen never promises what the worker cannot do.
 */
final class ChannelStatus
{
    /** Mail transports that accept a message without delivering it to anyone. */
    public const NON_DELIVERING_MAILERS = ['log', 'array'];

    public function __construct(
        private readonly SmsSender $sms,
        private readonly NotifyClient $notify,
        private readonly VapidKeys $vapid,
    ) {}

    public function mailer(): string
    {
        return (string) config('mail.default');
    }

    /** False when MAIL_MAILER only writes to a log or memory: nothing reaches an inbox. */
    public function mailDelivers(): bool
    {
        return ! in_array($this->mailer(), self::NON_DELIVERING_MAILERS, true);
    }

    public function pushConfigured(): bool
    {
        return $this->vapid->configured();
    }

    public function smsConfigured(): bool
    {
        return $this->sms->configured();
    }

    public function notifyEnabled(): bool
    {
        return $this->notify->enabled();
    }

    /** @return array<string, array{available: bool, state: string, message: string}> */
    public function summary(): array
    {
        return [
            'in_app' => ['available' => true, 'state' => 'on', 'message' => 'Always on. Everything we send appears in your notifications here.'],
            'email' => $this->mailDelivers()
                ? ['available' => true, 'state' => 'on', 'message' => 'Emails go to your sign-in address.']
                : ['available' => false, 'state' => 'not_delivering', 'message' => 'Email is not being delivered by Dot.Tutor right now (messages are only written to a log). Your choices are saved and apply once email delivery is switched on.'],
            'push' => $this->pushConfigured()
                ? ['available' => true, 'state' => 'configured', 'message' => 'Turn push on for each phone or browser you use.']
                : ['available' => false, 'state' => 'not_configured', 'message' => 'Push notifications are not set up on Dot.Tutor yet. Your choices are saved and apply once they are.'],
            'sms' => $this->smsConfigured()
                ? ['available' => true, 'state' => 'configured', 'message' => 'Used only for safety messages.']
                : ['available' => false, 'state' => 'not_configured', 'message' => 'Text messages (SMS) are not available on Dot.Tutor.'],
        ];
    }
}
