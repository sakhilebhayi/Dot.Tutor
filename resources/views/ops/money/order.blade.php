@php
    use App\Domain\Money\Money;
    $tz = config('tutor.timezone');
    $m = fn ($minor) => Money::of((int) $minor, $order->currency)->format();
@endphp
<x-layouts::operations title="Order {{ \Illuminate\Support\Str::limit($order->uuid, 8, '') }}">
    <x-td.page-head :title="'Order '.\Illuminate\Support\Str::limit($order->uuid, 8, '')" :lede="'Placed '.$order->created_at->timezone($tz)->format('j M Y, H:i').' by '.($order->buyer?->name ?? 'a deleted account').'. Amounts were frozen when the order was priced.'">
        <x-slot:actions><x-td.button :href="route('ops.payments')" icon="arrow-left" variant="quiet">Orders</x-td.button></x-slot:actions>
    </x-td.page-head>

    <div class="grid gap-6">
        <x-td.stat-strip label="Order totals" :items="[
            ['label' => 'State', 'value' => ucfirst(str_replace('_', ' ', $order->status))],
            ['label' => 'Total', 'value' => $m($order->total_minor), 'hint' => 'subtotal '.$m($order->subtotal_minor).', discount '.$m($order->discount_minor).', credit '.$m($order->credit_minor)],
            ['label' => 'Refunded', 'value' => $m($order->refunded_minor)],
            ['label' => 'Receipt', 'value' => $order->receipt?->number ?? '—', 'hint' => $order->receipt ? 'issued' : 'issued when paid'],
        ]" />

        @foreach ($order->lines as $line)
            @php($open = $line->cash_minor - $line->refunded_minor)
            <x-td.panel :title="$line->title">
                <dl class="ops-facts">
                    <dt>Seller</dt><dd>{{ $line->seller_id ? ($sellers[$line->seller_id] ?? 'User '.$line->seller_id) : 'Dot.Tutor' }}</dd>
                    <dt>Paid in cash</dt><dd class="ops-num">{{ $m($line->cash_minor) }}{{ $line->refunded_minor ? ', refunded '.$m($line->refunded_minor) : '' }}</dd>
                    <dt>Commission</dt><dd class="ops-num">{{ $m($line->commission_minor) }} ({{ rtrim(rtrim(number_format($line->commission_bps / 100, 2), '0'), '.') }}%)</dd>
                    <dt>Other charges</dt><dd class="ops-num">{{ $m($line->charges_minor) }}</dd>
                    <dt>Tutor's net</dt><dd class="ops-num">{{ $m($line->seller_net_minor) }}{{ $line->earning ? ' · earning '.$line->earning->status : '' }}</dd>
                </dl>
                @if ($canRefund && $open > 0 && in_array($order->status, ['paid', 'partially_refunded'], true))
                    <form method="POST" action="{{ route('ops.payments.refund', [$order, $line]) }}" class="ops-act mt-5">
                        @csrf
                        <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                        <x-td.field label="Amount (up to {{ $m($open) }})" for="amt-{{ $line->id }}"><x-td.input name="amount" id="amt-{{ $line->id }}" inputmode="decimal" :value="number_format($open / 100, 2, '.', '')" required /></x-td.field>
                        <x-td.field label="Reason" for="rsn-{{ $line->id }}"><x-td.select name="reason" id="rsn-{{ $line->id }}" :options="$reasons" /></x-td.field>
                        <x-td.field label="Note" for="nt-{{ $line->id }}" optional><x-td.input name="note" id="nt-{{ $line->id }}" /></x-td.field>
                        <x-td.button type="submit" variant="danger" size="sm">Refund through Paystack</x-td.button>
                    </form>
                @endif
                @if ($canManage && $line->earning && $line->earning->status === 'pending')
                    <form method="POST" action="{{ route('ops.payments.release', [$order, $line]) }}" class="ops-act mt-4">
                        @csrf
                        <x-td.field label="Why release the tutor's earning now" for="rel-{{ $line->id }}"><x-td.input name="note" id="rel-{{ $line->id }}" required /></x-td.field>
                        <x-td.button type="submit" size="sm">Release earning</x-td.button>
                    </form>
                @endif
            </x-td.panel>
        @endforeach

        <div class="td-panels td-panels--2">
            <x-td.panel title="Payments" flush>
                @if ($order->payments->isEmpty())
                    <x-td.state kind="empty" title="No payment attempts" />
                @else
                    <x-td.table caption="Payment attempts" caption-hidden dense>
                        <thead><tr><th scope="col">Reference</th><th scope="col">State</th><th scope="col" class="is-num">Amount</th><th scope="col" class="is-num">Paystack fee</th></tr></thead>
                        <tbody>@foreach ($order->payments as $p)<tr><td class="text-sm">{{ $p->reference }}</td><td>{{ $p->status }}{{ $p->channel ? ' · '.$p->channel : '' }}</td><td class="is-num">{{ $m($p->amount_minor) }}</td><td class="is-num">{{ $p->fees_minor !== null ? $m($p->fees_minor) : '—' }}</td></tr>@endforeach</tbody>
                    </x-td.table>
                @endif
            </x-td.panel>
            <x-td.panel title="Refunds" flush>
                @if ($order->refunds->isEmpty())
                    <x-td.state kind="empty" title="No refunds" />
                @else
                    <x-td.table caption="Refunds on this order" caption-hidden dense>
                        <thead><tr><th scope="col">When</th><th scope="col">State</th><th scope="col" class="is-num">Amount</th></tr></thead>
                        <tbody>@foreach ($order->refunds as $r)<tr><td class="ops-num text-sm">{{ $r->created_at->timezone($tz)->format('j M H:i') }}</td><td>{{ $r->status }}{{ $r->failure_message ? ': '.$r->failure_message : '' }}</td><td class="is-num">{{ $m($r->amount_minor) }}</td></tr>@endforeach</tbody>
                    </x-td.table>
                @endif
            </x-td.panel>
        </div>
    </div>
</x-layouts::operations>
