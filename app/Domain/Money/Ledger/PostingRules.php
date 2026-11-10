<?php

namespace App\Domain\Money\Ledger;

use App\Domain\Money\Models\CreditGrant;
use App\Domain\Money\Models\Dispute;
use App\Domain\Money\Models\Earning;
use App\Domain\Money\Models\Order;
use App\Domain\Money\Models\OrderLine;
use App\Domain\Money\Models\Payment;
use App\Domain\Money\Models\Payout;
use App\Domain\Money\Models\Refund;

/**
 * Every journal the money domain posts, in one place (docs/R47_MONEY.md "Posting rules"). Each builder
 * returns a balanced JournalEntry with an idempotency key derived from the business event.
 */
class PostingRules
{
    /**
     * An order is paid. Per line, the tutor's selling price (price minus their own discount) is split into
     * the tutor's pending earning, commission and other charges (or platform sales for a platform item); it is
     * funded by the card payment (less Paystack's fee), promotional credit and platform-funded discounts.
     */
    public function orderPaid(Order $order, ?Payment $payment): ?JournalEntry
    {
        $entry = new JournalEntry('order_paid', "order-paid:{$order->id}", $order->currency, "Order {$order->uuid} paid", [
            'order_id' => $order->id,
            'payment_id' => $payment?->id,
        ]);

        $cash = $payment === null ? 0 : $payment->amount_minor;
        $fees = $payment === null ? 0 : min($cash, (int) $payment->fees_minor);

        $entry->debit(Account::GATEWAY_CLEARING, $cash - $fees)
            ->debit(Account::GATEWAY_FEES, $fees)
            ->debit(Account::learnerCredit($order->buyer_id), $order->credit_minor);

        foreach ($order->lines as $line) {
            $entry->debit(Account::PROMOTIONS_EXPENSE, $line->platform_discount_minor);

            if ($line->seller_id === null) {
                $entry->credit(Account::PLATFORM_SALES, $line->commissionBaseMinor());

                continue;
            }

            $entry->credit(Account::tutorPending($line->seller_id), $line->seller_net_minor)
                ->credit(Account::PLATFORM_COMMISSION, $line->commission_minor)
                ->credit(Account::PLATFORM_FEES, $line->charges_minor);
        }

        return $entry->lines() === [] ? null : $entry;
    }

    /** A pending earning becomes available (or a pending reversal is applied to the available balance). */
    public function earningReleased(Earning $earning): ?JournalEntry
    {
        if ($earning->net_minor === 0) {
            return null;
        }

        $entry = new JournalEntry('earning_released', "earning-released:{$earning->id}", $earning->currency, "Earning #{$earning->id} available", [
            'earning_id' => $earning->id,
        ]);

        $amount = abs($earning->net_minor);

        return $earning->net_minor > 0
            ? $entry->debit(Account::tutorPending($earning->seller_id), $amount)->credit(Account::tutorPayable($earning->seller_id), $amount)
            : $entry->debit(Account::tutorPayable($earning->seller_id), $amount)->credit(Account::tutorPending($earning->seller_id), $amount);
    }

    /**
     * A refund is processed: the cash goes back out of Paystack (or the platform bank for a manual refund),
     * the same share of the sale is reversed (tutor earning, platform revenue as "refunds"), and the same share
     * of platform-funded discount and promotional credit is reversed (credit goes back to the learner).
     *
     * @return array{entry: JournalEntry, tutor_minor: int}
     */
    public function refundProcessed(Refund $refund, OrderLine $line, Order $order, bool $tutorFundsAvailable): array
    {
        $amount = $refund->amount_minor;
        $cash = max(1, $line->cash_minor);
        $promoBack = intdiv($line->platform_discount_minor * $amount + intdiv($cash, 2), $cash);
        $creditBack = intdiv($line->credit_minor * $amount + intdiv($cash, 2), $cash);
        $tutorBack = $line->seller_id === null ? 0 : intdiv($line->seller_net_minor * $amount + intdiv($cash, 2), $cash);

        // Never take back more than what the sale gave on each side.
        $promoBack = min($promoBack, $line->platform_discount_minor);
        $creditBack = min($creditBack, $line->credit_minor);
        $tutorBack = min($tutorBack, $line->seller_net_minor);
        $credits = $amount + $promoBack + $creditBack;
        $tutorBack = min($tutorBack, $credits);
        $platformBack = $credits - $tutorBack;

        $source = $refund->payment?->provider === 'paystack' ? Account::GATEWAY_CLEARING : Account::PLATFORM_BANK;
        $entry = new JournalEntry('refund', "refund:{$refund->id}", $refund->currency, "Refund #{$refund->id} for order {$order->uuid}", [
            'order_id' => $order->id,
            'payment_id' => $refund->payment_id,
            'refund_id' => $refund->id,
        ]);

        $entry->credit($source, $amount)
            ->credit(Account::PROMOTIONS_EXPENSE, $promoBack)
            ->credit(Account::learnerCredit($order->buyer_id), $creditBack)
            ->debit(Account::REFUNDS, $platformBack);

        if ($line->seller_id !== null) {
            $entry->debit($tutorFundsAvailable ? Account::tutorPayable($line->seller_id) : Account::tutorPending($line->seller_id), $tutorBack);
        }

        return ['entry' => $entry, 'tutor_minor' => $tutorBack];
    }

    public function payoutPaid(Payout $payout): JournalEntry
    {
        $source = $payout->method === 'paystack_transfer' ? Account::GATEWAY_CLEARING : Account::PLATFORM_BANK;

        return (new JournalEntry('payout', "payout:{$payout->id}", $payout->currency, "Payout {$payout->reference}", ['payout_id' => $payout->id]))
            ->debit(Account::tutorPayable($payout->payee_id), $payout->amount_minor)
            ->credit($source, $payout->amount_minor);
    }

    public function payoutReversed(Payout $payout): JournalEntry
    {
        $source = $payout->method === 'paystack_transfer' ? Account::GATEWAY_CLEARING : Account::PLATFORM_BANK;

        return (new JournalEntry('payout_reversal', "payout-reversal:{$payout->id}", $payout->currency, "Payout {$payout->reference} failed after it was marked paid", ['payout_id' => $payout->id]))
            ->debit($source, $payout->amount_minor)
            ->credit(Account::tutorPayable($payout->payee_id), $payout->amount_minor);
    }

    public function creditGranted(CreditGrant $grant, ?int $actorId): JournalEntry
    {
        $entry = new JournalEntry('credit', "credit:{$grant->id}", $grant->currency, 'Promotional credit: '.$grant->reason, [], $actorId);
        $amount = abs($grant->amount_minor);

        return $grant->amount_minor > 0
            ? $entry->debit(Account::PROMOTIONS_EXPENSE, $amount)->credit(Account::learnerCredit($grant->user_id), $amount)
            : $entry->debit(Account::learnerCredit($grant->user_id), $amount)->credit(Account::PROMOTIONS_EXPENSE, $amount);
    }

    public function disputeLost(Dispute $dispute): JournalEntry
    {
        return (new JournalEntry('dispute_lost', "dispute-lost:{$dispute->id}", (string) $dispute->currency, "Card dispute #{$dispute->id} lost", [
            'order_id' => $dispute->order_id,
            'payment_id' => $dispute->payment_id,
        ]))->debit(Account::DISPUTE_LOSSES, (int) $dispute->amount_minor)->credit(Account::GATEWAY_CLEARING, (int) $dispute->amount_minor);
    }
}
