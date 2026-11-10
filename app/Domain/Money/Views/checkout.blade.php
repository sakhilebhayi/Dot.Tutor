@php use App\Domain\Money\Money; use App\Domain\Money\Models\Order; $cur = $order->currency; $hasDiscount = $order->discount_minor > 0; $hasCredit = $order->credit_minor > 0; @endphp
<x-app-layout>
@include('money::partials.styles')
<div class="t-page">
    <h1 class="t-h1">{{ $order->isSettled() ? 'Order paid' : 'Check your order' }}</h1>
    <p class="t-lede">Prices come from the tutor's listing and are checked again when you pay. Nothing is charged until Paystack confirms the payment.</p>

    @if(session('money_status'))<div class="t-flash" role="status">{{ session('money_status') }}</div>@endif
    @error('money')<div class="t-flash" role="alert">{{ $message }}</div>@enderror

    <section class="t-panel" aria-labelledby="items-h">
        <h2 class="t-h2" id="items-h">What you are buying</h2>
        <div class="table-scroll" style="overflow-x:auto;" role="region" aria-label="Order items" tabindex="0">
            <table class="t-table m-lines">
                <thead><tr><th scope="col">Item</th><th scope="col" class="m-num m-hide-sm">Price</th>@if($hasDiscount)<th scope="col" class="m-num m-hide-sm">Discount</th>@endif @if($hasCredit)<th scope="col" class="m-num m-hide-sm">Credit</th>@endif<th scope="col" class="m-num">To pay</th></tr></thead>
                <tbody>
                @foreach($order->lines as $line)
                    <tr>
                        <td>{{ $line->title }}</td>
                        <td class="m-num m-hide-sm">{{ Money::of($line->price_minor, $cur)->format() }}</td>
                        @if($hasDiscount)<td class="m-num m-hide-sm">{{ $line->tutor_discount_minor + $line->platform_discount_minor ? '-'.Money::of($line->tutor_discount_minor + $line->platform_discount_minor, $cur)->format() : '' }}</td>@endif
                        @if($hasCredit)<td class="m-num m-hide-sm">{{ $line->credit_minor ? '-'.Money::of($line->credit_minor, $cur)->format() : '' }}</td>@endif
                        <td class="m-num">{{ Money::of($line->cash_minor, $cur)->format() }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <dl class="m-sum">
            <dt>Subtotal</dt><dd>{{ Money::of($order->subtotal_minor, $cur)->format() }}</dd>
            @if($order->discount_minor)<dt>Discount{{ $order->coupon ? ' ('.$order->coupon->code.')' : '' }}</dt><dd>-{{ Money::of($order->discount_minor, $cur)->format() }}</dd>@endif
            @if($order->credit_minor)<dt>Promotional credit</dt><dd>-{{ Money::of($order->credit_minor, $cur)->format() }}</dd>@endif
            <dt class="m-total">{{ $order->isSettled() ? 'Paid' : 'To pay now' }}</dt><dd class="m-total">{{ $order->total()->format() }}</dd>
            @if($order->refunded_minor)<dt>Refunded</dt><dd>{{ Money::of($order->refunded_minor, $cur)->format() }}</dd>@endif
        </dl>
    </section>

    <section class="t-panel" aria-labelledby="pay-h">
        <h2 class="t-h2" id="pay-h">Payment</h2>
        @if($order->isSettled())
            <p class="t-hint">Paid {{ $order->paid_at?->format('j M Y, H:i') }}.</p>
            @if($order->receipt)<div class="m-actions"><a class="t-btn t-btn-primary" href="{{ route('money.receipts.show', $order->receipt) }}">View receipt {{ $order->receipt->number }}</a></div>@endif
        @elseif($order->status === Order::AWAITING_GUARDIAN)
            <p class="m-note m-note-warn" role="status">A parent or guardian must approve this purchase before it can be paid. You will be able to pay here once they have approved it.</p>
        @elseif(! $order->isOpen())
            <p class="m-note" role="status">This order is {{ str_replace('_', ' ', $order->status) }} and can no longer be paid. Start a new order from the item.</p>
        @else
            @if($paymentNotice)
                <p class="m-note m-note-warn" role="status">{{ $paymentNotice }}</p>
            @else
                @if($testMode)<p class="m-note" role="note">Test mode: Paystack's test cards work here and no real money moves.</p>@endif
                <p class="t-hint" style="margin-top:1rem;">You will finish on Paystack's secure page (card, EFT or other methods Paystack offers), then come back here.</p>
            @endif
            <div class="m-actions">
                @if(! $paymentNotice || $order->total_minor === 0)
                    <form method="POST" action="{{ route('money.checkout.pay', $order) }}">
                        @csrf
                        <button type="submit" class="t-btn t-btn-primary">{{ $order->total_minor === 0 ? 'Complete order' : 'Pay '.$order->total()->format().' with Paystack' }}</button>
                    </form>
                @endif
                <form method="POST" action="{{ route('money.checkout.cancel', $order) }}">
                    @csrf
                    <button type="submit" class="t-btn t-btn-quiet">Cancel order</button>
                </form>
            </div>
            @if($order->expires_at)<p class="t-hint" style="margin-top:.75rem;">Held for you until {{ $order->expires_at->format('j M Y, H:i') }}.</p>@endif
        @endif
    </section>
</div>
</x-app-layout>
