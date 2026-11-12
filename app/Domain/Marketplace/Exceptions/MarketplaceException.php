<?php

namespace App\Domain\Marketplace\Exceptions;

use RuntimeException;

/**
 * A rule refused the action (wrong state, no seats, not allowed). Rendered on
 * /api/tutor/* as {error, message} with the given status.
 */
class MarketplaceException extends RuntimeException
{
    public function __construct(public readonly string $error, string $message, public readonly int $status = 409)
    {
        parent::__construct($message);
    }

    /**
     * A 4xx refusal is the rule's answer ("that group lesson is full", "not waiting for payment"), not a fault, so it
     * is not written to the error log; anything raised with a 5xx status is still reported.
     */
    public function report(): bool
    {
        return $this->status < 500;
    }
}
