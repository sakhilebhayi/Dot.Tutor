@php
    use App\Domain\Money\Money;
    $tz = config('tutor.timezone');
    $m = fn ($minor, $cur = null) => Money::of((int) $minor, $cur ?: Money::defaultCurrency())->format();
@endphp
<x-layouts::operations title="Payouts">
    <x-td.page-head title="Payouts" lede="Tutors are paid from available earnings in batches. Export the bank file, pay from the bank, then mark each payout paid or failed.">
        @if ($canPayout)
            <x-slot:actions>
                <form method="POST" action="{{ route('ops.payouts.draft') }}">@csrf<x-td.button type="submit" variant="primary" icon="plus">Draft a batch now</x-td.button></form>
            </x-slot:actions>
        @endif
    </x-td.page-head>

    <div class="grid gap-6">
        <x-td.stat-strip label="Tutor balances" :items="[
            ['label' => 'Available to pay', 'value' => $m($available), 'hint' => 'released earnings not yet in a payout'],
            ['label' => 'Still held', 'value' => $m($pending), 'hint' => 'inside the complaint or refund window'],
            ['label' => 'Minimum payout', 'value' => $m($minimum), 'hint' => 'smaller balances carry forward'],
            ['label' => 'Automatic batch', 'value' => $payoutDay, 'hint' => $transfers ? 'Paystack Transfers on' : 'manual bank payment; Paystack Transfers off'],
        ]" />

        <x-td.panel title="Batches" flush>
            @if ($batches->isEmpty())
                <x-td.state kind="empty" title="No payout batches yet">A batch is drafted automatically on {{ $payoutDay }} at 06:00, or now with the button above.</x-td.state>
            @else
                <x-td.table caption="Payout batches, newest first" caption-hidden stack>
                    <thead><tr><th scope="col">Batch</th><th scope="col">Up to</th><th scope="col">State</th><th scope="col" class="is-num">Payouts</th><th scope="col" class="is-num" data-stack="key">Total</th></tr></thead>
                    <tbody>
                        @foreach ($batches as $b)
                            <tr>
                                <td><a href="{{ route('ops.payouts.batch', $b) }}">Batch {{ $b->id }}</a></td>
                                <td class="ops-num text-sm">{{ $b->period_end?->timezone($tz)->format('j M Y') }}</td>
                                <td>{{ ucfirst($b->status) }}{{ $b->exported_at && $b->status !== 'exported' ? ', bank file exported' : '' }}</td>
                                <td class="is-num">{{ $b->payout_count }}</td>
                                <td class="is-num">{{ $m($b->total_minor, $b->currency) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-td.table>
                @if ($batches->hasPages())<div class="px-5 py-3"><x-td.pagination :paginator="$batches" label="Batch pages" /></div>@endif
            @endif
        </x-td.panel>
    </div>
</x-layouts::operations>
