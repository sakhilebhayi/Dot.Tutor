@php
    use App\Domain\Money\Money;
    use App\Domain\Ops\Http\Controllers\MoneyController;
    $tz = \App\Domain\Student\Support\Times::zone();
    $m = fn ($minor, $cur = null) => Money::of((int) $minor, $cur ?: Money::defaultCurrency())->format();
    $tabs = collect(MoneyController::TABS)->map(fn ($label, $key) => ['label' => $label, 'href' => route('ops.payments', ['tab' => $key]), 'current' => $tab === $key, 'count' => $counts[$key] ?? null])->values()->all();
    $statusTone = ['paid' => 'success', 'partially_refunded' => 'info', 'refunded' => 'info', 'pending_payment' => 'neutral', 'awaiting_guardian' => 'info', 'cancelled' => 'neutral', 'expired' => 'neutral', 'guardian_refused' => 'warning'];
@endphp
<x-layouts::operations title="Orders and payments">
    <x-td.page-head title="Orders and payments" :lede="'Every order, payment, refund, card dispute and exception. Money moves only through Paystack and the ledger; nothing on this screen edits an amount. '.\App\Domain\Student\Support\Times::zoneNote()" />

    @if (! $paystack['configured'])
        <x-td.notice tone="warning" title="Paystack is not configured" class="mb-5">No secret key is set, so learners cannot pay online and refunds cannot be sent. Set PAYSTACK_SECRET_KEY (test key first).</x-td.notice>
    @endif

    <x-td.tabs :items="$tabs" label="Money" class="mb-5" />

    @if ($tab === 'orders')
        <form method="GET" action="{{ route('ops.payments') }}" class="ops-act mb-5">
            <input type="hidden" name="tab" value="orders">
            <x-td.field label="State" for="o-status"><x-td.select name="status" id="o-status" :value="$status" placeholder="Any" :options="collect(array_keys($statusTone))->mapWithKeys(fn ($s) => [$s => ucfirst(str_replace('_', ' ', $s))])->all()" /></x-td.field>
            <x-td.button type="submit" icon="funnel">Filter</x-td.button>
        </form>
        <x-td.panel flush>
            @if ($orders->isEmpty())
                <x-td.state kind="empty" title="No orders{{ $status ? ' in this state' : ' yet' }}">Orders appear when a learner checks out a lesson, a course, a resource or a room place.</x-td.state>
            @else
                <x-td.table caption="Orders, newest first" caption-hidden stack>
                    <thead><tr><th scope="col">Order</th><th scope="col">Buyer</th><th scope="col" data-stack="wide">Items</th><th scope="col">State</th><th scope="col" class="is-num" data-stack="key">Total</th><th scope="col" class="is-num">Refunded</th></tr></thead>
                    <tbody>
                        @foreach ($orders as $o)
                            <tr>
                                <td class="text-sm"><a href="{{ route('ops.payments.order', $o) }}">{{ \Illuminate\Support\Str::limit($o->uuid, 8, '') }}</a><div class="ops-muted ops-num">{{ $o->created_at->timezone($tz)->format('j M H:i') }}</div></td>
                                <td>{{ $o->buyer?->name ?? 'Deleted account' }}</td>
                                <td class="text-sm">{{ $o->lines->pluck('title')->join(', ') }}</td>
                                <td>@include('ops.partials.tag', ['text' => ucfirst(str_replace('_', ' ', $o->status)), 'tone' => $statusTone[$o->status] ?? 'neutral'])</td>
                                <td class="is-num">{{ $m($o->total_minor, $o->currency) }}</td>
                                <td class="is-num">{{ $o->refunded_minor > 0 ? $m($o->refunded_minor, $o->currency) : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-td.table>
                @if ($orders->hasPages())<div class="px-5 py-3"><x-td.pagination :paginator="$orders" label="Order pages" /></div>@endif
            @endif
        </x-td.panel>
    @elseif ($tab === 'refunds')
        <x-td.panel flush>
            @if ($refunds->isEmpty())
                <x-td.state kind="empty" title="No refunds yet">Refunds are made from an order line by a finance operator.</x-td.state>
            @else
                <x-td.table caption="Refunds, unfinished first" caption-hidden stack>
                    <thead><tr><th scope="col">Requested</th><th scope="col" data-stack="title">Order</th><th scope="col">Reason</th><th scope="col">State</th><th scope="col" class="is-num" data-stack="key">Amount</th></tr></thead>
                    <tbody>
                        @foreach ($refunds as $r)
                            <tr>
                                <td class="ops-num text-sm">{{ $r->created_at->timezone($tz)->format('j M Y H:i') }}</td>
                                <td class="text-sm">@php($ord = \App\Domain\Money\Models\Order::query()->find($r->order_id))@if ($ord)<a href="{{ route('ops.payments.order', $ord) }}">{{ \Illuminate\Support\Str::limit($ord->uuid, 8, '') }}</a>@endif</td>
                                <td>{{ \App\Domain\Money\Models\Refund::REASONS[$r->reason] ?? $r->reason }}</td>
                                <td>@include('ops.partials.tag', match ($r->status) {
                                    'processed' => ['text' => 'Processed', 'tone' => 'success'],
                                    'failed' => ['text' => 'Failed: '.\Illuminate\Support\Str::limit((string) $r->failure_message, 60), 'tone' => 'danger'],
                                    default => ['text' => 'Waiting for Paystack', 'tone' => 'info', 'icon' => 'hourglass'],
                                })</td>
                                <td class="is-num">{{ $m($r->amount_minor, $r->currency) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-td.table>
                @if ($refunds->hasPages())<div class="px-5 py-3"><x-td.pagination :paginator="$refunds" label="Refund pages" /></div>@endif
            @endif
        </x-td.panel>
    @elseif ($tab === 'exceptions')
        <x-td.panel flush>
            @if ($issues->isEmpty())
                <x-td.state kind="success" title="No exceptions">Payments for references Dot.Tutor never issued, refunds due after a cancellation or dispute, paid items that could not be delivered and failed webhooks appear here.</x-td.state>
            @else
                <ul class="ops-work">
                    @foreach ($issues as $i)
                        <li>
                            <div class="grid gap-2">
                                <p class="ops-work__title">{{ $i->summary }}</p>
                                <p class="ops-work__meta">
                                    @include('ops.partials.tag', ['text' => $i->kind === 'unmatched_payment' ? 'Unmatched payment' : ucfirst(str_replace('_', ' ', $i->kind)), 'tone' => $i->severity === 'critical' ? 'danger' : 'warning'])
                                    · {{ $i->created_at->timezone($tz)->format('j M Y H:i') }}
                                    @if ($i->amount_minor) · {{ $m($i->amount_minor, $i->currency) }}@endif
                                    @if ($i->status !== 'open') · resolved: {{ $i->resolution_note }}@endif
                                </p>
                                @if ($i->status === 'open' && $canManage)
                                    <form method="POST" action="{{ route('ops.payments.issues.resolve', $i) }}" class="ops-act">
                                        @csrf
                                        <x-td.field label="What you did" for="iss-{{ $i->id }}"><x-td.input name="note" id="iss-{{ $i->id }}" required /></x-td.field>
                                        <x-td.button type="submit" size="sm">Mark resolved</x-td.button>
                                    </form>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
                @if ($issues->hasPages())<div class="px-5 py-3"><x-td.pagination :paginator="$issues" label="Exception pages" /></div>@endif
            @endif
        </x-td.panel>
        <p class="mt-3 text-sm ops-muted">An unmatched payment was verified with Paystack but matches no order. Find the payer from the Paystack dashboard, then refund it there or credit the learner, and record what you did here.</p>
    @elseif ($tab === 'disputes')
        <x-td.panel flush>
            @if ($disputes->isEmpty())
                <x-td.state kind="success" title="No card disputes">Chargebacks reported by Paystack appear here with a critical exception.</x-td.state>
            @else
                <ul class="ops-work">
                    @foreach ($disputes as $d)
                        <li>
                            <div class="grid gap-2">
                                <p class="ops-work__title">{{ $d->reference }} · {{ $d->amount_minor ? $m($d->amount_minor, $d->currency) : 'amount not known' }}</p>
                                <p class="ops-work__meta">@include('ops.partials.tag', ['text' => ucfirst($d->status), 'tone' => $d->status === 'open' ? 'warning' : ($d->status === 'lost' ? 'danger' : 'success')]) {{ $d->reason }} {{ $d->notes }}</p>
                                @if ($d->status === 'open' && $canManage)
                                    <form method="POST" action="{{ route('ops.payments.disputes.outcome', $d) }}" class="ops-act">
                                        @csrf
                                        <x-td.field label="Note" for="dsp-{{ $d->id }}" optional><x-td.input name="note" id="dsp-{{ $d->id }}" /></x-td.field>
                                        <x-td.button type="submit" name="status" value="won" size="sm">Won</x-td.button>
                                        <x-td.button type="submit" name="status" value="lost" size="sm" variant="danger">Lost</x-td.button>
                                        <x-td.button type="submit" name="status" value="resolved" size="sm" variant="quiet">Resolved otherwise</x-td.button>
                                    </form>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
                @if ($disputes->hasPages())<div class="px-5 py-3"><x-td.pagination :paginator="$disputes" label="Dispute pages" /></div>@endif
            @endif
        </x-td.panel>
    @else
        <x-td.panel title="Commission rules" flush>
            @if ($rules->isEmpty())
                <x-td.state kind="empty" title="No commission rule recorded">Every sale uses the configured default of {{ rtrim(rtrim(number_format($defaultPercent, 2), '0'), '.') }}% (MONEY_COMMISSION_PERCENT), a placeholder the owner must decide.</x-td.state>
            @else
                <x-td.table caption="Commission rules, by category and version" caption-hidden dense>
                    <thead><tr><th scope="col">Category</th><th scope="col">Version</th><th scope="col" class="is-num">Commission</th><th scope="col">In force</th><th scope="col">Reason</th></tr></thead>
                    <tbody>
                        @foreach ($rules as $r)
                            <tr>
                                <td>{{ $r->category ? ucfirst(str_replace('_', ' ', $r->category)) : 'Platform default' }}</td>
                                <td class="ops-num">{{ $r->version }}</td>
                                <td class="is-num">{{ rtrim(rtrim(number_format($r->percent_bps / 100, 2), '0'), '.') }}%</td>
                                <td class="text-sm">{{ $r->effective_from->timezone($tz)->format('j M Y') }}{{ $r->superseded_at ? ' to '.$r->superseded_at->timezone($tz)->format('j M Y') : ', current' }}</td>
                                <td class="text-sm">{{ $r->reason }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-td.table>
            @endif
        </x-td.panel>
        <p class="mt-3 text-sm ops-muted">Rules are versioned and never edited; each order line keeps the rule it was priced with. New rules are added through the finance API (POST /api/tutor/money/ops/commission-rules) by a finance operator.</p>
    @endif
</x-layouts::operations>
