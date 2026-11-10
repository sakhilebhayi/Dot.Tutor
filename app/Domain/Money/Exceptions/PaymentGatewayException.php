<?php

namespace App\Domain\Money\Exceptions;

use RuntimeException;

/**
 * Paystack could not be used. The message is always safe to show a person: never a key, a response body
 * or a status code. `reason`: not_configured | live_key_refused | unavailable | rejected | invalid_response.
 */
class PaymentGatewayException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function because(string $reason, string $message): self
    {
        return new self($reason, $message);
    }
}
