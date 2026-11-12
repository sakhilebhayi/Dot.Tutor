<?php

namespace App\Domain\Money\Http\Controllers;

use App\Domain\Money\CheckoutStart;
use App\Domain\Money\Contracts\PurchaseApprovals;
use App\Domain\Money\Models\Earning;
use App\Domain\Money\Models\Order;
use App\Domain\Money\Models\Payment;
use App\Domain\Money\Models\Payout;
use App\Domain\Money\Models\Receipt;
use App\Domain\Money\Paystack\PaystackConfig;
use App\Domain\Money\Services\Earnings;
use App\Domain\Money\Services\Orders;
use App\Domain\Money\Services\Payments;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Minimal functional pages: checkout, return from Paystack, receipt, tutor earnings, finance operations.
 */
class MoneyPagesController extends Controller
{
    public function checkout(Request $request, Order $order, PaystackConfig $paystack): View
    {
        $user = $request->user();
        abort_unless($order->buyer_id === $user->id || app(PurchaseApprovals::class)->canPayFor($user, $order), 404);

        return view('money::checkout', [
            'order' => $order->load('lines', 'coupon', 'receipt'),
            'paymentNotice' => $order->total_minor > 0 ? $paystack->notice() : null,
            'testMode' => $paystack->isTestMode(),
        ]);
    }

    public function pay(Request $request, Order $order, Orders $orders): RedirectResponse|Response
    {
        $start = $orders->startPayment($order, $request->user());

        if ($start->status === CheckoutStart::REDIRECT) {
            // Paystack's own checkout host only (checked when the address was received).
            return redirect()->away((string) $start->redirectUrl);
        }

        return redirect()->route('money.checkout.show', $order)->with('money_status', (string) $start->message);
    }

    public function cancel(Request $request, Order $order, Orders $orders): RedirectResponse
    {
        $orders->cancel($order, $request->user());

        return redirect()->route('money.checkout.show', $order)->with('money_status', 'Order cancelled. Nothing was charged.');
    }

    public function checkoutReturn(Request $request, Payments $payments): RedirectResponse
    {
        $reference = (string) $request->query('reference', $request->query('trxref', ''));
        $payment = $reference === '' ? null : Payment::where('reference', $reference)->first();
        abort_if($payment === null, 404);

        $order = $payment->order;
        $user = $request->user();
        abort_unless($order->buyer_id === $user->id || app(PurchaseApprovals::class)->canPayFor($user, $order), 404);

        $payment = $payments->verify($payment);

        $message = match ($payment->status) {
            Payment::PAID => 'Payment received. Thank you.',
            Payment::FAILED, Payment::ABANDONED => 'The payment did not go through. Nothing was charged; you can try again.',
            default => 'Paystack has not confirmed the payment yet. This page will show it as soon as it does.',
        };

        return redirect()->route('money.checkout.show', $order)->with('money_status', $message);
    }

    public function receipt(Request $request, Receipt $receipt): View
    {
        abort_unless($receipt->buyer_id === $request->user()->id || Gate::allows('money.finance'), 404);

        return view('money::receipt', ['receipt' => $receipt]);
    }

    public function earnings(Request $request, Earnings $earnings): View
    {
        $id = $request->user()->id;

        return view('money::earnings', [
            'balances' => $earnings->balances($id),
            'earnings' => Earning::with('orderLine')->where('seller_id', $id)->orderByDesc('id')->limit(50)->get(),
            'payouts' => Payout::where('payee_id', $id)->orderByDesc('id')->limit(20)->get(),
        ]);
    }

    /**
     * The old finance desk (R-47 fix D-2400): it printed UTC wall-clock times on a learner-shell page. Everything it
     * listed (open exceptions, orders, refunds in flight, payout batches) lives in the operations console, in the
     * operator's own time zone and with the actions beside it, so the address now forwards there.
     */
    public function ops(): RedirectResponse
    {
        return redirect()->route('ops.payments', ['tab' => 'exceptions']);
    }
}
