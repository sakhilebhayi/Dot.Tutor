<?php

namespace App\Domain\Learning\Support;

final readonly class PriceQuote
{
    public function __construct(
        public int $listMinor,
        public int $payableMinor,
        public string $currency,
        public ?string $couponCode = null,
        public bool $couponAccepted = false,
        public ?string $message = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'list_minor' => $this->listMinor,
            'payable_minor' => $this->payableMinor,
            'currency' => $this->currency,
            'coupon_code' => $this->couponCode,
            'coupon_accepted' => $this->couponAccepted,
            'message' => $this->message,
        ];
    }
}
