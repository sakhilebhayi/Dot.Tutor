@php
    use App\Domain\Money\Money;
    $tz = config('tutor.timezone');
    $m = fn ($minor) => Money::of((int) $minor, $batch->currency ?: Money::defaultCurrency())->format();
@endphp
<x-layouts::operations title="Payout batch {{ $batch->id }}">
    <x-td.page-head :title="'Payout batch '.$batch->id" :lede="'Earnings available up to '.$batch->period_end?->timezone($tz)->format('j M Y').'. '.($batch->exported_at ? 'Exported '.$batch->exported_at->timezone($tz)->format('j M Y H:i').'.' : 'Not exported yet.')">
        <x-slot:actions>
            <x-td.button :href="route('ops.payouts')" icon="arrow-left" variant="quiet">Payouts</x-td.button>
            @if ($canPayout && $payouts->isNotEmpty())<x-td.button :href="route('money.api.ops.batches.export', $batch)" icon="download">Bank file (CSV)</x-td.button>@endif
        </x-slot:actions>
    </x-td.page-head>

    <div class="grid gap-6">
        @if (! empty($batch->carried_forward))
            <x-td.notice tone="info" title="Carried forward">{{ collect($batch->carried_forward)->map(fn ($c) => is_array($c) ? (($c['reason'] ?? '') ?: 'below minimum') : $c)->countBy()->map(fn ($n, $why) => $n.' '.str_replace('_', ' ', $why))->join(', ') }}.</x-td.notice>
        @endif
        <x-td.panel flush>
            @if ($payouts->isEmpty())
                <x-td.state kind="empty" title="Nobody to pay in this batch">No tutor had at least the minimum available with a bank account on file.</x-td.state>
            @else
                <x-td.table caption="Payouts in this batch" caption-hidden stack>
                    <thead><tr><th scope="col">Tutor</th><th scope="col">Reference</th><th scope="col">Account</th><th scope="col">State</th><th scope="col" class="is-num" data-stack="key">Amount</th><th scope="col" data-stack="action"><span class="td-sr">Actions</span></th></tr></thead>
                    <tbody>
                        @foreach ($payouts as $p)
                            <tr>
                                <td>{{ $payees[$p->payee_id] ?? 'User '.$p->payee_id }}</td>
                                <td class="text-sm">{{ $p->reference }}</td>
                                <td class="text-sm">{{ $p->account?->account_last4 ? 'ending '.$p->account->account_last4 : 'none' }}</td>
                                <td>@include('ops.partials.tag', match ($p->status) { 'paid' => ['text' => 'Paid', 'tone' => 'success'], 'failed' => ['text' => 'Failed', 'tone' => 'danger'], 'processing' => ['text' => 'Transfer in progress', 'tone' => 'info'], default => ['text' => 'To pay'] })</td>
                                <td class="is-num">{{ $m($p->amount_minor) }}</td>
                                <td>
                                    @if ($canPayout && in_array($p->status, ['draft'], true))
                                        <div class="flex flex-wrap gap-2 items-end">
                                            <form method="POST" action="{{ route('ops.payouts.paid', $p) }}">@csrf<x-td.button type="submit" size="sm">Mark paid</x-td.button></form>
                                            <form method="POST" action="{{ route('ops.payouts.failed', $p) }}" class="ops-act">@csrf
                                                <x-td.field label="Why it failed" for="fail-{{ $p->id }}"><x-td.input name="reason" id="fail-{{ $p->id }}" required /></x-td.field>
                                                <x-td.button type="submit" size="sm" variant="quiet">Mark failed</x-td.button></form>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-td.table>
            @endif
        </x-td.panel>
    </div>
</x-layouts::operations>
