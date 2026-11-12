@php $m = fn ($money) => \App\Domain\Teach\Support\TutorContext::money($money); @endphp
<x-layouts::workspace :title="'Payout '.$statement['reference']">
    <x-td.page-head :title="'Payout '.$statement['reference']" :lede="'Status: '.$statement['status'].($statement['paid_at'] ? ', paid '.\Carbon\CarbonImmutable::parse($statement['paid_at'])->timezone($tz)->format('j M Y') : '').($statement['account_ending'] ? ', to the account ending '.$statement['account_ending'] : '').'.'">
        <x-slot:actions><x-td.button href="{{ route('teach.payouts') }}" icon="arrow-left">Payouts</x-td.button></x-slot:actions>
    </x-td.page-head>
    <x-td.panel title="What this payout holds" flush>
        <x-td.table caption="Earnings in this payout" caption-hidden dense stack>
            <thead><tr><th scope="col">Item</th><th scope="col" class="is-num">Price</th><th scope="col" class="is-num">Commission</th><th scope="col" class="is-num">Other charges</th><th scope="col" class="is-num" data-stack="key">You keep</th></tr></thead>
            <tbody>
                @foreach ($statement['earnings'] as $e)
                    <tr><td>{{ $e['title'] ?? 'Sale' }}{{ $e['kind'] === 'reversal' ? ' (refund adjustment)' : '' }}</td><td class="is-num">{{ $m($e['gross']) }}</td><td class="is-num">{{ $m($e['commission']) }}</td><td class="is-num">{{ $m($e['other_charges']) }}</td><td class="is-num">{{ $m($e['net']) }}</td></tr>
                @endforeach
            </tbody>
            <tfoot><tr><th scope="row" colspan="4">Payout amount</th><td class="is-num font-bold">{{ $m($statement['amount']) }}</td></tr></tfoot>
        </x-td.table>
    </x-td.panel>
    <p class="mt-3 text-sm text-td-ink-2">To keep a copy, print this page or save it as a PDF from your browser's print menu.</p>
</x-layouts::workspace>
