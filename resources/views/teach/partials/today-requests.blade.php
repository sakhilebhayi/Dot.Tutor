{{-- Part of teach/home (Today); included with the parent's variables. --}}
<div class="td-panels td-panels--2">
    <x-td.panel title="Requests to answer">
        <x-slot:actions>
            <a class="td-link text-sm" href="{{ route('teach.bookings') }}">All bookings</a>
        </x-slot:actions>
        @if ($requestCount === 0)
            {{-- One line when empty, so it never pushes real work down (D-2408). --}}
            <p class="m-0">No requests waiting · <a class="td-link" href="{{ route('teach.storefront') }}">Share your storefront</a></p>
            <p class="mb-0 mt-1 text-sm text-td-ink-2">When a learner asks for a lesson, it appears here to accept or decline.</p>
        @else
            @foreach ($series as $sr)
                @php $first = $sr->sessions->first(); @endphp
                <div class="mb-3 flex flex-wrap items-center justify-between gap-3 border-b border-td-line pb-3">
                    <p class="m-0"><strong>Weekly lessons</strong>, {{ $sr->sessions->where('status', 'pending')->count() }} weeks from {{ $first?->starts_at->copy()->timezone($tz)->format('D j M, H:i') }}</p>
                    <form method="POST" action="{{ route('teach.bookings.series.accept', $sr) }}">@csrf<x-td.button type="submit" variant="primary" size="sm">Accept the series</x-td.button></form>
                </div>
            @endforeach
            <ul class="m-0 list-none p-0">
                @foreach ($requests->whereNull('tutor_booking_series_id')->take(6) as $s)
                    @include('teach.partials.booking-row', ['s' => $s, 'tz' => $tz])
                @endforeach
            </ul>
            @if ($requests->whereNull('tutor_booking_series_id')->count() > 6)
                <p><a class="td-link" href="{{ route('teach.bookings') }}">See all {{ $requests->count() }} requests</a></p>
            @endif
        @endif
    </x-td.panel>

    <x-td.panel title="Money">
        <x-slot:actions><a class="td-link text-sm" href="{{ route('teach.earnings') }}">Earnings</a></x-slot:actions>
        <x-td.stat-strip label="Your earnings" :items="[
            ['label' => 'Available', 'value' => $money_($money['available']), 'hint' => 'Can be paid out'],
            ['label' => 'Pending', 'value' => $money_($money['pending']), 'hint' => $money['next_release'] ? 'Next frees up '.$money['next_release']['at']->format('j M') : ($money['awaiting_delivery'] > 0 ? 'Waiting for lessons to be delivered' : 'Nothing pending')],
        ]" />
        <p class="mt-4 mb-1 text-sm">Next payout run: <strong>{{ $money['next_run']->format('D j M, H:i') }}</strong></p>
        @if ($money['blocker'])
            <p class="m-0 text-sm text-td-ink-2">{{ $money['blocker'] }} @unless ($money['has_account'])<a class="td-link" href="{{ route('teach.payouts') }}#bank">Add bank details</a>@endunless</p>
        @else
            <p class="m-0 text-sm text-td-ink-2">Available earnings are drafted into a payout then; the bank transfer follows when finance pays it.</p>
        @endif
    </x-td.panel>
</div>
