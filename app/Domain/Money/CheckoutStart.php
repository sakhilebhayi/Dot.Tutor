<?php

namespace App\Domain\Money;

use App\Domain\Money\Models\Order;

/**
 * The answer to "start payment": go to Paystack, settled already (nothing due at a card), held for a
 * guardian, or online payment is not available on this environment.
 */
final readonly class CheckoutStart
{
    public const REDIRECT = 'redirect';

    public const SETTLED = 'settled';

    public const HELD = 'held_for_guardian';

    public const UNAVAILABLE = 'unavailable';

    public function __construct(
        public string $status,
        public Order $order,
        public ?string $redirectUrl = null,
        public ?string $message = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toApi(): array
    {
        return array_filter([
            'status' => $this->status,
            'redirect_url' => $this->redirectUrl,
            'message' => $this->message,
            'order' => $this->order->toApi(),
        ], fn ($v) => $v !== null);
    }
}
