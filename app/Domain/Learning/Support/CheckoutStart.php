<?php

namespace App\Domain\Learning\Support;

final readonly class CheckoutStart
{
    /** @param  string  $status  unavailable | redirect | pending */
    public function __construct(
        public string $status,
        public string $message,
        public ?string $redirectUrl = null,
        public ?string $orderReference = null,
    ) {}

    public static function unavailable(string $message): self
    {
        return new self('unavailable', $message);
    }
}
