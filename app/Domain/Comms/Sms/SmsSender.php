<?php

namespace App\Domain\Comms\Sms;

/**
 * SMS adapter (optional, off). No provider is built (decision D-1006); a driver implements this and is bound by
 * the owner's choice. Until then every SMS copy is recorded as "not available on this service".
 */
interface SmsSender
{
    public function configured(): bool;

    public function name(): string;

    /** @return array{status: 'sent'|'failed'|'retry', provider_id: ?string, error: ?string} */
    public function send(string $number, string $text): array;
}
