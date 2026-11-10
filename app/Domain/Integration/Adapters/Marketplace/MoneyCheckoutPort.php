<?php

namespace App\Domain\Integration\Adapters\Marketplace;

use App\Domain\Integration\Support\MoneyTypes;
use App\Domain\Integration\Support\OrderStarter;
use App\Domain\Marketplace\Contracts\CheckoutPort;
use App\Domain\Marketplace\Exceptions\MarketplaceException;
use App\Domain\Marketplace\Support\CheckoutResult;
use App\Domain\Money\CheckoutStart;
use App\Domain\Money\Exceptions\CheckoutRefused;
use App\Models\User;

/**
 * Marketplace payments through the money domain. The price is never taken from the descriptor: money asks the
 * registered purchasable (booking, seat, package) for it. A paid result comes back to PaymentService::paid through
 * the OrderPaid listener (and directly when nothing was due at a card).
 */
class MoneyCheckoutPort implements CheckoutPort
{
    public function __construct(private readonly OrderStarter $orders) {}

    public function isConfigured(): bool
    {
        return $this->orders->unavailableReason() === null;
    }

    public function start(User $payer, array $purchasable, string $idempotencyKey): CheckoutResult
    {
        $type = MoneyTypes::MARKETPLACE_TO_MONEY[$purchasable['type']] ?? null;
        if ($type === null) {
            throw new MarketplaceException('unknown_purchasable', 'This item cannot be paid for.', 422);
        }

        if (($off = $this->orders->unavailableReason()) !== null && $this->orders->openOrderFor($payer, $type, (int) $purchasable['id']) === null) {
            return new CheckoutResult('unconfigured', $off.' Nothing has been charged.');
        }

        try {
            $start = $this->orders->start($payer, $type, (int) $purchasable['id'], $idempotencyKey);
        } catch (CheckoutRefused $e) {
            throw new MarketplaceException($e->reason, $e->getMessage(), $e->status);
        }

        $reference = $start->order->uuid;

        return match ($start->status) {
            CheckoutStart::REDIRECT => new CheckoutResult('pending', 'Continue to Paystack to pay. The booking is confirmed once Paystack confirms the payment.', $reference, $start->redirectUrl),
            CheckoutStart::SETTLED => new CheckoutResult('paid', $start->message ?? 'Paid.', $reference),
            CheckoutStart::HELD => new CheckoutResult('awaiting_guardian', $start->message ?? 'A parent or guardian must approve this payment first.', $reference),
            default => new CheckoutResult('unconfigured', $start->message ?? 'Online payment is not available on Dot.Tutor yet. Nothing has been charged.', $reference),
        };
    }
}
