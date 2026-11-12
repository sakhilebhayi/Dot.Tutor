@php
    $m = fn ($money) => \App\Domain\Teach\Support\TutorContext::money($money);
    $t = $statement['totals'];
    $statusWords = ['pending' => 'Pending', 'available' => 'Available', 'paid' => 'Paid out', 'cancelled' => 'Cancelled'];
    $ruleWords = ['on_delivery' => 'after the lesson is delivered', 'after_window' => 'after the refund window'];
    $presets = [
        'This month' => [now($tz)->startOfMonth(), now($tz)],
        'Last month' => [now($tz)->subMonthNoOverflow()->startOfMonth(), now($tz)->subMonthNoOverflow()->endOfMonth()],
        'This year' => [now($tz)->startOfYear(), now($tz)],
    ];
@endphp
<x-layouts::workspace title="Earnings" step="dense">
    <x-td.page-head title="Earnings" :lede="'From '.$from->format('j M Y').' to '.$to->format('j M Y').': you kept '.strip_tags((string) $m($t['net'])).' from '.strip_tags((string) $m($t['gross'])).' in sales.'">
        <x-slot:actions>
            <x-td.button href="{{ route('teach.earnings.export', ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')]) }}" icon="download">Income report (CSV)</x-td.button>
        </x-slot:actions>
    </x-td.page-head>

    <x-td.stat-strip label="Balances now" class="mb-6" :items="[
        ['label' => 'Available', 'value' => $m($glance['available']), 'hint' => 'Goes into the next payout run'],
        ['label' => 'Pending', 'value' => $m($glance['pending']), 'hint' => $glance['next_release'] ? 'Next frees up '.$glance['next_release']['at']->format('j M, H:i') : 'Frees up after delivery or the refund window'],
        ['label' => 'Paid out', 'value' => $m($glance['paid_out']), 'hint' => 'Since you started'],
        ['label' => 'Next payout run', 'value' => $glance['next_run']->format('D j M'), 'hint' => $glance['blocker'] ?? 'Minimum '.strip_tags((string) $m($glance['minimum']))],
    ]" />

    <form method="GET" action="{{ route('teach.earnings') }}" class="mb-6 flex flex-wrap items-end gap-3" aria-label="Period">
        <x-td.field label="From" for="from"><x-td.input name="from" type="date" :value="$from->format('Y-m-d')" /></x-td.field>
        <x-td.field label="To" for="to"><x-td.input name="to" type="date" :value="$to->format('Y-m-d')" /></x-td.field>
        <x-td.button type="submit">Show</x-td.button>
        @foreach ($presets as $label => [$pf, $pt])
            <a class="td-link text-sm" href="{{ route('teach.earnings', ['from' => $pf->format('Y-m-d'), 'to' => $pt->format('Y-m-d')]) }}">{{ $label }}</a>
        @endforeach
    </form>

    <x-td.panel title="Every sale and adjustment" flush>
        @if ($rows->isEmpty())
            <div class="p-5">
                <x-td.state kind="empty" title="No earnings in this period">
                    An earning appears when a learner pays: the price, the platform's commission, any other charge and what you keep, line by line. Lesson earnings stay pending until the lesson is delivered plus {{ (int) config('money.earnings.hold_hours_after_lesson') }} hours; courses and materials until the refund window ends.
                </x-td.state>
            </div>
        @else
            <x-td.table caption="Earnings in this period" caption-hidden dense>
                <thead><tr>
                    <th scope="col">Date</th><th scope="col">Item</th>
                    <th scope="col" class="is-num">Price</th><th scope="col" class="is-num">Commission</th><th scope="col" class="is-num">Other charges</th><th scope="col" class="is-num">You keep</th>
                    <th scope="col">Status</th>
                </tr></thead>
                <tbody>
                    @foreach ($rows as $e)
                        @php $p = \App\Domain\Money\Services\Earnings::present($e); @endphp
                        <tr>
                            <td class="tabular-nums">{{ $e->created_at->copy()->timezone($tz)->format('j M Y') }}</td>
                            @php $label = $labels[$e->id] ?? ['what' => $p['title'] ?? 'Sale', 'who' => null]; @endphp
                            <td>{{ $label['what'] }}@if ($label['who'])<span class="block text-sm text-td-ink-2">{{ $label['who'] }}</span>@endif @if ($e->kind === 'reversal')<span class="block text-sm text-td-ink-2">Refund adjustment</span>@endif</td>
                            <td class="is-num">{{ $m($p['gross']) }}</td>
                            <td class="is-num">{{ $m($p['commission']) }}</td>
                            <td class="is-num">{{ $p['other_charges']->isZero() ? '—' : $m($p['other_charges']) }}</td>
                            <td class="is-num font-bold">{{ $m($p['net']) }}</td>
                            <td>
                                {{ $statusWords[$e->status] ?? $e->status }}
                                @if ($e->status === 'pending')
                                    <span class="block text-sm text-td-ink-2">{{ $e->release_after ? 'From '.$e->release_after->copy()->timezone($tz)->format('j M, H:i') : 'Frees up '.($ruleWords[$e->release_rule] ?? 'later') }}</span>
                                @elseif ($e->status === 'paid' && $e->payout_id)
                                    <a class="block text-sm td-link" href="{{ route('teach.payouts.show', $e->payout_id) }}">Payout statement</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <th scope="row" colspan="2">Period totals</th>
                        <td class="is-num">{{ $m($t['gross']) }}</td>
                        <td class="is-num">{{ $m($t['commission']) }}</td>
                        <td class="is-num">{{ $m($t['other_charges']) }}</td>
                        <td class="is-num font-bold">{{ $m($t['net']) }}</td>
                        <td>{{ $t['refunded']->isZero() ? '' : 'Includes '.strip_tags((string) $m($t['refunded'])).' refunded' }}</td>
                    </tr>
                </tfoot>
            </x-td.table>
            @if ($rows->hasPages())<div class="p-4"><x-td.pagination :paginator="$rows" label="Earnings pages" /></div>@endif
        @endif
    </x-td.panel>
    <p class="mt-3 text-sm text-td-ink-2">Commission is taken from your price after any discount you funded; platform-funded discounts and credits do not lower what you keep. Payment processing fees are {{ (float) config('money.charges.pass_gateway_fee_percent', 0) > 0 ? 'passed on to you, shown under other charges' : 'paid by the platform' }}.</p>
</x-layouts::workspace>
