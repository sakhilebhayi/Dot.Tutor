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
}
