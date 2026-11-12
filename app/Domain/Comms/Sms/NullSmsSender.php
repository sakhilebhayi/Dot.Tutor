<?php

namespace App\Domain\Comms\Sms;

/** The honest default: SMS is not configured, nothing is sent and nobody's number is asked for. */
final class NullSmsSender implements SmsSender
{
    public function configured(): bool
    {
        return false;
    }

    public function name(): string
    {
        return 'none';
    }

    public function send(string $number, string $text): array
    {
        return ['status' => 'failed', 'provider_id' => null, 'error' => 'sms_not_configured'];
    }
}
