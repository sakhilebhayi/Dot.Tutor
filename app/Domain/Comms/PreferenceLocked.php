<?php

namespace App\Domain\Comms;

use RuntimeException;

/** A mandatory category's channels cannot be switched off. */
final class PreferenceLocked extends RuntimeException
{
    public function __construct(public readonly string $category, string $reason = '')
    {
        parent::__construct($reason !== '' ? $reason : 'This kind of message cannot be switched off.');
    }
}
