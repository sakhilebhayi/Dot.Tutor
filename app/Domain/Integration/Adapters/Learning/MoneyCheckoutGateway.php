<?php

namespace App\Domain\Integration\Adapters\Learning;

use App\Domain\Integration\Support\MoneyTypes;
use App\Domain\Integration\Support\OrderStarter;
use App\Domain\Learning\Contracts\Seams\CheckoutGateway;
use App\Domain\Learning\Contracts\Sellable;
use App\Domain\Learning\Support\CheckoutStart;
use App\Domain\Learning\Support\PriceQuote;
use App\Domain\Money\CheckoutStart as MoneyCheckoutStart;
use App\Domain\Money\Exceptions\CheckoutRefused;
use App\Domain\Money\Services\Orders;
use App\Models\User;

/**
 * Course, bundle and resource prices, coupons and checkout through the money domain. A refusal (already owned,
 * guardian declined, coupon invalid at checkout) is thrown as money's CheckoutRefused, which renders {error,
 * message} for JSON and a form error otherwise.
 */
class MoneyCheckoutGateway implements CheckoutGateway
{
    public function __construct(private readonly Orders $orders, private readonly OrderStarter $starter) {}

    public function quote(User $buyer, Sellable $item, ?string $couponCode = null): PriceQuote
    {
        $items = [['type' => MoneyTypes::learningToMoney($item->sellableType()), 'id' => $item->sellableId()]];

        try {
            $quote = $this->orders->quote($buyer, $items, $couponCode);
        } catch (CheckoutRefused $e) {
            if ($e->reason === 'already_in_open_order' && ($open = $this->starter->openOrderFor($buyer, $items[0]['type'], $items[0]['id'])) !== null) {
                return new PriceQuote((int) $open->subtotal_minor, (int) $open->total_minor, $open->currency, $couponCode, false,
                    'You already have an unpaid order for this. Pay or cancel that order first.');
            }

            if ($couponCode !== null && $couponCode !== '') {
                try {
                    $plain = $this->orders->quote($buyer, $items);

                    return new PriceQuote($plain['subtotal']->minor, $plain['total']->minor, $plain['currency'], $couponCode, false, $e->getMessage());
                } catch (CheckoutRefused) {
                    // fall through to the list price with the first refusal
                }
            }

            return new PriceQuote($item->priceMinor(), $item->priceMinor(), $item->currency(), $couponCode, false, $e->getMessage());
        }

        return new PriceQuote(
            listMinor: $quote['subtotal']->minor,
            payableMinor: $quote['total']->minor,
            currency: $quote['currency'],
            couponCode: $couponCode,
            couponAccepted: $quote['coupon'] !== null,
            message: $quote['coupon'] !== null ? 'Coupon applied.' : null,
        );
    }

    public function start(User $buyer, Sellable $item, ?string $couponCode, string $idempotencyKey): CheckoutStart
    {
        $type = MoneyTypes::learningToMoney($item->sellableType());
        if (($off = $this->starter->unavailableReason()) !== null && $this->starter->openOrderFor($buyer, $type, $item->sellableId()) === null) {
            return CheckoutStart::unavailable($off);
        }

        $start = $this->starter->start($buyer, MoneyTypes::learningToMoney($item->sellableType()), $item->sellableId(), $idempotencyKey, $couponCode);
        $reference = $start->order->uuid;

        return match ($start->status) {
            MoneyCheckoutStart::REDIRECT => new CheckoutStart('redirect', 'Continue to Paystack to pay. Access opens once Paystack confirms the payment.', $start->redirectUrl, $reference),
            MoneyCheckoutStart::SETTLED => new CheckoutStart('paid', $start->message ?? 'Paid: you can start now.', null, $reference),
            MoneyCheckoutStart::HELD => new CheckoutStart('awaiting_guardian', $start->message ?? 'A parent or guardian must approve this purchase first.', null, $reference),
            default => CheckoutStart::unavailable($start->message ?? 'Online payment is not available yet, so paid content cannot be bought.'),
        };
    }
}
