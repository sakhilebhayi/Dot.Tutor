@php use App\Domain\Money\Money; @endphp
<x-app-layout>
@include('money::partials.styles')
<div class="t-page">
    <h1 class="t-h1">Finance operations</h1>
    <p class="t-lede">Exceptions to resolve, recent orders, refunds in flight and payout batches. Actions (refunds, credits, payouts, reconciliation) run through the finance API under /api/tutor/money/ops; every one is audited.</p>

    <section class="t-panel" aria-labelledby="iss-h">
        <h2 class="t-h2" id="iss-h">Open exceptions ({{ $issues->count() }})</h2>
        @if($issues->isEmpty())
            <p class="t-hint">Nothing to resolve.</p>
        @else
        <div style="overflow-x:auto;" role="region" aria-label="Open exceptions" tabindex="0">
            <table class="t-table">
                <thead><tr><th scope="col">Raised</th><th scope="col">Kind</th><th scope="col">Summary</th><th scope="col" class="m-num">Amount</th></tr></thead>
                <tbody>
                @foreach($issues as $i)
                    <tr><td>{{ $i->created_at->format('j M H:i') }}</td><td><span class="t-state {{ $i->severity === 'critical' ? 't-state-suspended' : 't-state-pending' }}">{{ str_replace('_', ' ', $i->kind) }}</span></td><td>{{ $i->summary }}</td><td class="m-num">{{ $i->amount_minor !== null ? Money::of($i->amount_minor, $i->currency ?? Money::defaultCurrency())->format() : '' }}</td></tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </section>

    <section class="t-panel" aria-labelledby="ord-h">
        <h2 class="t-h2" id="ord-h">Recent orders</h2>
        @if($orders->isEmpty())
            <p class="t-hint">No orders yet.</p>
        @else
        <div style="overflow-x:auto;" role="region" aria-label="Recent orders" tabindex="0">
            <table class="t-table">
                <thead><tr><th scope="col">Created</th><th scope="col">Order</th><th scope="col">Status</th><th scope="col" class="m-num">Total</th><th scope="col" class="m-num">Refunded</th></tr></thead>
                <tbody>
                @foreach($orders as $o)
                    <tr><td>{{ $o->created_at->format('j M H:i') }}</td><td class="t-mono">{{ \Illuminate\Support\Str::limit($o->uuid, 8, '') }}</td><td>{{ str_replace('_', ' ', $o->status) }}</td><td class="m-num">{{ $o->total()->format() }}</td><td class="m-num">{{ $o->refunded_minor ? Money::of($o->refunded_minor, $o->currency)->format() : '' }}</td></tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </section>

    <div class="t-grid2">
        <section class="t-panel" aria-labelledby="ref-h">
            <h2 class="t-h2" id="ref-h">Refunds in flight</h2>
            @forelse($refunds as $r)
                <p class="t-hint" style="margin:.4rem 0;">#{{ $r->id }}: {{ Money::of($r->amount_minor, $r->currency)->format() }}, {{ $r->status }}{{ $r->failure_message ? ' ('.$r->failure_message.')' : '' }}</p>
            @empty
                <p class="t-hint">None.</p>
            @endforelse
        </section>
        <section class="t-panel" aria-labelledby="bat-h">
            <h2 class="t-h2" id="bat-h">Payout batches</h2>
            @forelse($batches as $b)
                <p class="t-hint" style="margin:.4rem 0;">#{{ $b->id }}: {{ $b->payout_count }} payout(s), {{ Money::of($b->total_minor, $b->currency)->format() }}, {{ $b->status }}</p>
            @empty
                <p class="t-hint">No batches yet.</p>
            @endforelse
        </section>
    </div>
</div>
</x-app-layout>
