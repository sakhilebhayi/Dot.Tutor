<?php

namespace App\Domain\Money\Paystack;

/**
 * Where to send the payer (a Paystack checkout host only) for our reference.
 */
final readonly class PaystackInitialization
{
    public function __construct(public string $authorizationUrl, public string $reference) {}
}
