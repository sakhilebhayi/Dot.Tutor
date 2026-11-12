{{-- /money/checkout/{order} (money.checkout.show): the order before paying (D-1113). From the money controller:
     $order, $paymentNotice, $testMode. Every button posts to the money domain's own routes. --}}
@php use App\Domain\Money\Money; use App\Domain\Money\Models\Order; use App\Domain\Student\Support\Times; $cur = $order->currency; @endphp
<x-student.shell :title="$order->isSettled() ? 'Order paid' : 'Check your order'">
    <x-td.page-head :title="$order->isSettled() ? 'Order paid' : 'Check your order'" lede="Prices come from the tutor's listing and are checked again when you pay. Nothing is charged until Paystack confirms the payment.">
        <x-slot:actions><x-td.button variant="quiet" icon="receipt" :href="route('money.orders')">Purchases</x-td.button></x-slot:actions>
    </x-td.page-head>

    @if (session('money_status'))<x-td.notice tone="info">{{ session('money_status') }}</x-td.notice>@endif
    @error('money')<x-td.notice tone="danger">{{ $message }}</x-td.notice>@enderror

    <div class="st-cols">
        <x-td.panel title="What you are buying" flush>
            <x-td.table caption="Order items">
                <thead><tr><th scope="col">Item</th><th scope="col" class="is-num">Price</th>@if ($order->discount_minor)<th scope="col" class="is-num">Discount</th>@endif @if ($order->credit_minor)<th scope="col" class="is-num">Credit</th>@endif<th scope="col" class="is-num">To pay</th></tr></thead>
                <tbody>
                    @foreach ($order->lines as $line)
                        <tr>
                            <td>{{ $line->title }}</td>
                            <td class="is-num">{{ Money::of($line->price_minor, $cur)->format() }}</td>
                            @if ($order->discount_minor)<td class="is-num">{{ $line->tutor_discount_minor + $line->platform_discount_minor ? '−'.Money::of($line->tutor_discount_minor + $line->platform_discount_minor, $cur)->format() : '' }}</td>@endif
                            @if ($order->credit_minor)<td class="is-num">{{ $line->credit_minor ? '−'.Money::of($line->credit_minor, $cur)->format() : '' }}</td>@endif
                            <td class="is-num">{{ Money::of($line->cash_minor, $cur)->format() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-td.table>
            <div class="td-panel__body">
                <dl class="st-facts">
                    <dt>Subtotal</dt><dd>{{ Money::of($order->subtotal_minor, $cur)->format() }}</dd>
                    @if ($order->discount_minor)<dt>Discount{{ $order->coupon ? ' ('.$order->coupon->code.')' : '' }}</dt><dd>−{{ Money::of($order->discount_minor, $cur)->format() }}</dd>@endif
                    @if ($order->credit_minor)<dt>Promotional credit</dt><dd>−{{ Money::of($order->credit_minor, $cur)->format() }}</dd>@endif
                    @if ($order->refunded_minor)<dt>Refunded</dt><dd>{{ Money::of($order->refunded_minor, $cur)->format() }}</dd>@endif
                </dl>
                <div class="st-total" style="margin-top:var(--td-space-3)"><span>{{ $order->isSettled() ? 'Paid' : 'To pay now' }}</span><span>{{ $order->total()->format() }}</span></div>
            </div>
        </x-td.panel>

        <x-td.panel title="Payment">
            <div class="st-stack">
                @if ($order->isSettled())
                    <p style="margin:0">Paid {{ $order->paid_at ? Times::when($order->paid_at) : '' }}.</p>
                    @if ($order->receipt)<div><x-td.button variant="primary" icon="receipt" :href="route('money.receipts.show', $order->receipt)">View receipt {{ $order->receipt->number }}</x-td.button></div>@endif
                @elseif ($order->status === Order::AWAITING_GUARDIAN)
                    <x-td.notice tone="info">A parent or guardian must approve this purchase before it can be paid. You can pay here once they have approved it.</x-td.notice>
                @elseif (! $order->isOpen())
                    <x-td.notice>This order is {{ str_replace('_', ' ', $order->status) }} and can no longer be paid. Start again from the item.</x-td.notice>
                @else
                    @if ($paymentNotice)
                        <x-td.notice tone="warning">{{ $paymentNotice }}</x-td.notice>
                    @else
                        @if ($testMode)<x-td.notice tone="info" title="Test mode">Paystack's test cards work here and no real money moves.</x-td.notice>@endif
                        <p class="st-small" style="margin:0">You finish on Paystack's secure page (card, EFT or other methods Paystack offers), then come back here.</p>
                    @endif
                    <div class="st-inline">
                        @if (! $paymentNotice || $order->total_minor === 0)
                            <form method="POST" action="{{ route('money.checkout.pay', $order) }}">@csrf
                                <x-td.button type="submit" variant="primary" size="lg" icon="wallet">{{ $order->total_minor === 0 ? 'Complete order' : 'Pay '.$order->total()->format() }}</x-td.button>
                            </form>
                        @endif
                        <form method="POST" action="{{ route('money.checkout.cancel', $order) }}">@csrf<x-td.button type="submit" variant="quiet">Cancel order</x-td.button></form>
                    </div>
                    @if ($order->expires_at)<p class="st-small" style="margin:0">Held for you until {{ Times::when($order->expires_at) }}.</p>@endif
                @endif
            </div>
        </x-td.panel>
    </div>
</x-student.shell>
