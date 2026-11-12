@php
    $requestCount = $requests->whereNull('tutor_booking_series_id')->count() + $series->count();
    $finding = match (true) {
        $state['key'] !== 'approved' => $state['label'].'. '.($missing === [] ? 'Your profile has every required step.' : 'Still to do: '.implode(', ', $missing).'.'),
        $requestCount > 0 => $requestCount.' '.\Illuminate\Support\Str::plural('request', $requestCount).' waiting for your answer.',
        $today->isNotEmpty() => $today->count().' '.\Illuminate\Support\Str::plural('lesson', $today->count()).' today.',
        default => 'Nothing waiting on you right now.',
    };
    $money_ = fn ($m) => \App\Domain\Teach\Support\TutorContext::money($m);
@endphp
<x-layouts::workspace title="Today">
    <x-td.page-head title="Today" :lede="$now->format('l j F').'. '.$finding">
        <x-slot:actions>
            @if ($state['key'] !== 'approved')
                <x-td.button href="{{ route('teach.profile') }}" variant="primary" icon="badge-check">Continue your profile</x-td.button>
            @else
                <x-td.button href="{{ route('teach.offerings.create') }}" icon="plus">New offering</x-td.button>
                <x-td.button href="{{ route('teach.calendar') }}" icon="calendar">Calendar</x-td.button>
            @endif
        </x-slot:actions>
    </x-td.page-head>

    <div class="td-panels td-panels--2">
        <x-td.panel title="Requests to answer">
            <x-slot:actions>
                <a class="td-link text-sm" href="{{ route('teach.bookings') }}">All bookings</a>
            </x-slot:actions>
            @if ($requestCount === 0)
                <x-td.state kind="empty" title="No requests waiting">When a learner asks for a lesson, it appears here to accept or decline. Learners find you through your storefront and matching.
                    <x-slot:actions><x-td.button href="{{ route('teach.storefront') }}" size="sm">Share your storefront</x-td.button></x-slot:actions>
                </x-td.state>
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

    <div class="td-panels td-panels--2 mt-6">
        <x-td.panel title="Lessons today and this week">
            @if ($today->isEmpty() && $week->isEmpty())
                <x-td.state kind="empty" title="No lessons booked this week">Booked lessons show here with the actions you need on the day: start, complete, or record a no-show.</x-td.state>
            @else
                <h3 class="mb-1 text-sm font-bold text-td-ink-2">Today</h3>
                @if ($today->isEmpty())
                    <p class="text-sm text-td-ink-2">No lessons today.</p>
                @else
                    <ul class="m-0 list-none p-0">
                        @foreach ($today as $s)
                            @include('teach.partials.booking-row', ['s' => $s, 'tz' => $tz])
                        @endforeach
                    </ul>
                @endif
                <h3 class="mt-4 mb-1 text-sm font-bold text-td-ink-2">Next 7 days</h3>
                @if ($week->isEmpty())
                    <p class="text-sm text-td-ink-2">Nothing else this week.</p>
                @else
                    <ul class="m-0 list-none p-0">
                        @foreach ($week->take(6) as $s)
                            @include('teach.partials.booking-row', ['s' => $s, 'tz' => $tz, 'compact' => true])
                        @endforeach
                    </ul>
                    @if ($week->count() > 6)<p class="text-sm"><a class="td-link" href="{{ route('teach.calendar') }}">{{ $week->count() - 6 }} more in the calendar</a></p>@endif
                @endif
            @endif
        </x-td.panel>

        <div class="td-panels content-start border-0">
            <x-td.panel title="Needs attention">
                @if ($attention === [])
                    <p class="m-0 text-td-ink-2">Nothing overdue, disputed or about to lapse.</p>
                @else
                    <ul class="m-0 grid list-none gap-3 p-0">
                        @foreach (array_slice($attention, 0, 6) as $item)
                            <li>
                                <p class="m-0 font-bold">{{ $item['session']->isGroup() ? 'Group lesson' : \App\Domain\Teach\Support\TutorContext::learnerName($item['session']->student) }}, {{ $item['session']->starts_at->copy()->timezone($tz)->format('j M H:i') }}</p>
                                <p class="m-0 text-sm text-td-ink-2">{{ $item['reason'] }}</p>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-td.panel>
            <x-td.panel title="Waiting on a guardian">
                @if ($guardianHolds === 0)
                    <p class="m-0 text-td-ink-2">No requests from under-18s are waiting for a parent or guardian.</p>
                @else
                    <p class="m-0"><strong>{{ $guardianHolds }}</strong> {{ \Illuminate\Support\Str::plural('request', $guardianHolds) }} from a learner under 18 {{ $guardianHolds === 1 ? 'is' : 'are' }} waiting for their guardian. You see who and when once a guardian approves; nothing is needed from you now.</p>
                @endif
            </x-td.panel>
            <x-td.panel title="Messages">
                <p class="m-0">
                    @if ($unread > 0)
                        <strong>{{ $unread }}</strong> unread {{ \Illuminate\Support\Str::plural('message', $unread) }}.
                    @else
                        No unread messages{{ $conversationCount === 0 ? ' yet' : '' }}.
                    @endif
                    <a class="td-link" href="{{ $messagesUrl }}">Open messages</a>
                </p>
                @if ($assistantUrl)
                    <p class="mb-0 mt-3 text-sm text-td-ink-2">Planning a lesson? <a class="td-link" href="{{ $assistantUrl }}">Teaching assistant</a></p>
                @endif
            </x-td.panel>
        </div>
    </div>
</x-layouts::workspace>
