@php use App\Domain\Money\Money; @endphp
<x-app-layout>
@include('money::partials.styles')
<div class="t-page">
    <h1 class="t-h1">Earnings</h1>
    <p class="t-lede">A lesson's earning stays pending until the lesson is completed, plus {{ (int) config('money.earnings.hold_hours_after_lesson') }} hours for complaints. Courses and materials become available when their refund window ends. Available earnings go out in the next payout batch.</p>

    <section class="t-panel" aria-labelledby="bal-h">
        <h2 class="t-h2" id="bal-h">Balances</h2>
        <dl class="m-balances" style="margin-top:1rem;">
            <div><dt>Pending</dt><dd>{{ $balances['pending']->format() }}</dd></div>
            <div><dt>Available for payout</dt><dd>{{ $balances['available']->format() }}</dd></div>
            <div><dt>Paid out</dt><dd>{{ $balances['paid_out']->format() }}</dd></div>
        </dl>
    </section>

    <section class="t-panel" aria-labelledby="earn-h">
        <h2 class="t-h2" id="earn-h">Recent sales</h2>
        @if($earnings->isEmpty())
            <p class="t-hint">No sales yet. When a learner pays for one of your lessons or courses, it appears here with the commission and what you keep.</p>
        @else
        <div style="overflow-x:auto;" role="region" aria-label="Recent sales" tabindex="0">
            <table class="t-table">
                <thead><tr><th scope="col">Item</th><th scope="col" class="m-num">Price</th><th scope="col" class="m-num">Commission</th><th scope="col" class="m-num">Other charges</th><th scope="col" class="m-num">You keep</th><th scope="col">Status</th></tr></thead>
                <tbody>
                @foreach($earnings as $e)
                    <tr>
                        <td>{{ $e->kind === 'reversal' ? 'Refund: ' : '' }}{{ $e->orderLine?->title }}</td>
                        <td class="m-num">{{ $e->kind === 'sale' ? Money::of($e->gross_minor, $e->currency)->format() : '' }}</td>
                        <td class="m-num">{{ $e->kind === 'sale' ? Money::of($e->commission_minor, $e->currency)->format() : '' }}</td>
                        <td class="m-num">{{ $e->kind === 'sale' ? Money::of($e->charges_minor, $e->currency)->format() : '' }}</td>
                        <td class="m-num">{{ Money::of($e->net_minor, $e->currency)->format() }}</td>
                        <td><span class="t-state {{ $e->status === 'available' || $e->status === 'paid' ? 't-state-approved' : ($e->status === 'pending' ? 't-state-pending' : '') }}">{{ $e->status }}</span>
                            @if($e->status === 'pending' && $e->release_after)<span class="t-hint" style="display:block;margin:.2rem 0 0;">from {{ $e->release_after->format('j M') }}</span>@endif</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </section>

    <section class="t-panel" aria-labelledby="pay-h">
        <h2 class="t-h2" id="pay-h">Payouts</h2>
        @if($payouts->isEmpty())
            <p class="t-hint">No payouts yet. Add your bank account so finance can include you in the next batch.</p>
        @else
        <div style="overflow-x:auto;" role="region" aria-label="Payouts" tabindex="0">
            <table class="t-table">
                <thead><tr><th scope="col">Reference</th><th scope="col" class="m-num">Amount</th><th scope="col">Status</th><th scope="col">Paid</th></tr></thead>
                <tbody>
                @foreach($payouts as $p)
                    <tr><td class="t-mono">{{ $p->reference }}</td><td class="m-num">{{ Money::of($p->amount_minor, $p->currency)->format() }}</td><td><span class="t-state {{ $p->status === 'paid' ? 't-state-approved' : ($p->status === 'failed' ? 't-state-suspended' : 't-state-pending') }}">{{ $p->status }}</span></td><td>{{ $p->paid_at?->format('j M Y') }}</td></tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </section>
</div>
</x-app-layout>
