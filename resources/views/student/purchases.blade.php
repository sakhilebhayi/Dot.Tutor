@php
    use App\Domain\Student\Support\Times;
    $statusWords = [
        'pending_payment' => ['Not paid yet', 'circle-alert', 'lapsed'], 'awaiting_guardian' => ['Waiting for your guardian', 'hourglass', 'review'],
        'paid' => ['Paid', 'circle-check', 'verified'], 'partially_refunded' => ['Partly refunded', 'receipt', 'peer'], 'refunded' => ['Refunded', 'receipt', 'peer'],
        'cancelled' => ['Cancelled', 'x', 'self'], 'expired' => ['Lapsed', 'x', 'self'], 'guardian_refused' => ['Your guardian said no', 'x', 'self'],
    ];
@endphp
<x-student.shell title="Purchases">
    <x-td.page-head title="Purchases and receipts" lede="Everything you paid for or started to pay for. Prices come from the tutor's listing; nothing is charged until Paystack confirms." />

    <x-td.panel title="Orders" flush>
        @if ($orders->isEmpty())
            <x-td.state kind="empty" title="No purchases yet">When you pay for a lesson, a package, a course or study material, it shows here with its receipt.</x-td.state>
        @else
            <ul class="st-list">
                @foreach ($orders as $order)
                    @php [$word, $icon, $tone] = $statusWords[$order->status] ?? [ucfirst(str_replace('_', ' ', $order->status)), 'info', 'self']; @endphp
                    <li class="st-row">
                        <div>
                            <p class="st-row__title">{{ $order->lines->pluck('title')->implode(', ') ?: 'Order' }}</p>
                            <p class="st-row__meta">{{ Times::when($order->created_at) }} · {{ $order->total()->format() }}@if ($order->refunded_minor) · {{ \App\Domain\Money\Money::of($order->refunded_minor, $order->currency)->format() }} refunded @endif</p>
                        </div>
                        <div class="st-row__end">
                            <span class="td-badge td-badge--{{ $tone }}"><x-td.icon :name="$icon" />{{ $word }}</span>
                            @if ($order->receipt)
                                <x-td.button size="sm" :href="route('money.receipts.show', $order->receipt)">Receipt {{ $order->receipt->number }}</x-td.button>
                            @elseif ($order->isOpen())
                                <x-td.button size="sm" variant="primary" :href="route('money.checkout.show', $order)">{{ $order->status === 'awaiting_guardian' ? 'See the order' : 'Pay or cancel' }}</x-td.button>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-td.panel>

    <p class="st-small" style="margin:0">Something wrong with a payment? Use <a class="td-link" href="{{ route('safety.conversations.index') }}">messages and support</a>. Refunds follow each tutor's cancellation terms and are processed by Dot.Tutor's finance team through Paystack.</p>
</x-student.shell>
