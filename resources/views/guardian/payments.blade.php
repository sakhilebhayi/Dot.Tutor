{{-- Payments: what each child bought or asked to buy, and who paid. Orders come from the money domain, requests from
     the safety domain's purchase approvals. Only the guardian's own linked children. --}}
@php
    use App\Domain\Money\Money;
    $tz = config('tutor.timezone');
    $orderText = ['paid' => 'Paid', 'partially_refunded' => 'Partly refunded', 'refunded' => 'Refunded', 'pending_payment' => 'Not paid yet', 'awaiting_guardian' => 'Waiting for you', 'cancelled' => 'Cancelled', 'expired' => 'Expired', 'guardian_refused' => 'You declined'];
    $rows = collect($children ?? [])->flatMap(fn ($c) => collect($c['orders'])->map(fn ($o) => $o + ['child' => $c['name']]))->sortByDesc('created_at')->values();
@endphp
<x-layouts::learner title="Payments">
    <x-td.page-head title="Payments" lede="Everything your children bought on Dot.Tutor, what it cost and who paid. Nothing is charged to a child under 18 without your approval." />

    @if (! $is_guardian)
        @include('guardian.partials.not-linked')
    @else
        <x-td.panel title="Orders" flush>
            @if ($rows->isEmpty())
                <x-td.state kind="empty" title="No purchases">When your child buys a lesson, course or paid room, it asks you first, then the order appears here.</x-td.state>
            @else
                <ul class="ops-work">
                    @foreach ($rows as $o)
                        <li>
                            <div class="grid gap-1">
                                <p class="ops-work__title">{{ $o['items'] ?: 'Order' }}</p>
                                <p class="ops-work__meta">{{ $o['child'] }} · <time datetime="{{ $o['created_at']->toIso8601String() }}">{{ $o['created_at']->copy()->timezone($tz)->format('j M Y') }}</time>{{ $o['paid_by_guardian'] ? ' · paid by a guardian' : '' }}{{ $o['refunded_minor'] > 0 ? ' · '.Money::of((int) $o['refunded_minor'], $o['currency'])->format().' refunded' : '' }}</p>
                            </div>
                            <div class="grid gap-1 justify-items-end text-right">
                                <span class="td-money">{{ Money::of((int) $o['total_minor'], $o['currency'])->format() }}</span>
                                @include('ops.partials.tag', ['text' => $orderText[$o['status']] ?? ucfirst(str_replace('_', ' ', $o['status'])), 'tone' => $o['status'] === 'paid' ? 'success' : ($o['status'] === 'awaiting_guardian' ? 'warning' : 'neutral')])
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-td.panel>
    @endif
</x-layouts::learner>
