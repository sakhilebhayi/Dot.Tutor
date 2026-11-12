<?php

namespace App\Domain\Marketplace\Support;

/**
 * unconfigured: no payment provider is connected; nothing was charged.
 * pending: a payment was started; the learner may need to follow redirectUrl.
 * paid: verified server-side by the money domain.
 * failed: the provider refused or errored.
 */
final readonly class CheckoutResult
{
    public function __construct(
        public string $status,
        public string $message,
        public ?string $orderReference = null,
        public ?string $redirectUrl = null,
    ) {}

    public static function unconfigured(): self
    {
        return new self('unconfigured', 'Payments are not configured on Dot.Tutor yet, so this booking is waiting for payment. Nothing has been charged.');
    }

    /** @return array{status: string, message: string, order_reference: ?string, redirect_url: ?string} */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'message' => $this->message,
            'order_reference' => $this->orderReference,
            'redirect_url' => $this->redirectUrl,
        ];
    }
}
