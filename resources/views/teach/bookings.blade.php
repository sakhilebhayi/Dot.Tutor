@php
    $tabs = [
        ['label' => 'Requests', 'href' => route('teach.bookings', ['view' => 'requests']), 'current' => $view === 'requests', 'count' => $counts['requests'] ?: null],
        ['label' => 'Upcoming', 'href' => route('teach.bookings', ['view' => 'upcoming']), 'current' => $view === 'upcoming'],
        ['label' => 'Waiting', 'href' => route('teach.bookings', ['view' => 'waiting']), 'current' => $view === 'waiting', 'count' => $counts['waiting'] ?: null],
        ['label' => 'Group lessons', 'href' => route('teach.bookings', ['view' => 'groups']), 'current' => $view === 'groups'],
        ['label' => 'Packages sold', 'href' => route('teach.bookings', ['view' => 'packages']), 'current' => $view === 'packages'],
        ['label' => 'Past', 'href' => route('teach.bookings', ['view' => 'past']), 'current' => $view === 'past'],
        ['label' => 'Cancelled', 'href' => route('teach.bookings', ['view' => 'cancelled']), 'current' => $view === 'cancelled'],
    ];
    $empty = [
        'requests' => ['No requests waiting', 'New lesson requests appear here. Accept to hold the time; decline with a reason the learner reads.'],
        'upcoming' => ['No upcoming lessons', 'Accepted and paid lessons show here, with Start from 15 minutes before.'],
        'waiting' => ['Nothing waiting for payment', 'Lessons you accepted show here until the learner pays; the time is held until then.'],
        'past' => ['No past lessons yet', 'Completed lessons, no-shows and disputes are kept here.'],
        'cancelled' => ['Nothing cancelled', 'Cancelled and declined bookings are kept here with their reasons.'],
    ];
@endphp
<x-layouts::workspace title="Requests and bookings" step="dense">
    <x-td.page-head title="Requests and bookings" :lede="'Times in '.$tz.'. Every action is checked again on the server against the booking\'s state and the offering\'s terms.'">
        <x-slot:actions>
            <x-td.button href="{{ route('teach.calendar') }}" icon="calendar">Calendar</x-td.button>
        </x-slot:actions>
    </x-td.page-head>

    <x-td.tabs label="Booking views" :items="$tabs" class="mb-6" />

    @if ($view === 'packages')
        <x-td.panel title="Packages learners bought" flush>
            @if ($packages->isEmpty())
                <div class="p-5"><x-td.state kind="empty" title="No packages sold yet">A lesson package is an offering: learners buy several lessons at once and book them one by one.
                    <x-slot:actions><x-td.button href="{{ route('teach.offerings.create', ['type' => 'package']) }}" size="sm">Create a package</x-td.button></x-slot:actions>
                </x-td.state></div>
            @else
                <x-td.table caption="Packages sold" caption-hidden dense>
                    <thead><tr><th scope="col">Learner</th><th scope="col">Package</th><th scope="col">State</th><th scope="col" class="is-num">Used</th><th scope="col" class="is-num">Left</th><th scope="col" class="is-num">Price</th><th scope="col">Expires</th></tr></thead>
                    <tbody>
                        @foreach ($packages as $p)
                            <tr>
                                <td>{{ \App\Domain\Teach\Support\TutorContext::learnerName($p->learner) }}</td>
                                <td>{{ $p->offering?->title }}</td>
                                <td>{{ str_replace('_', ' ', ucfirst($p->status)) }}</td>
                                <td class="is-num">{{ $p->lessons_used }} of {{ $p->lessons_total }}</td>
                                <td class="is-num">{{ $p->creditsLeft() }}</td>
                                <td class="is-num"><x-td.money :minor="$p->price_minor" :currency="$p->currency" /></td>
                                <td>{{ $p->expires_at ? $p->expires_at->copy()->timezone($tz)->format('j M Y') : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-td.table>
                @if ($packages->hasPages())<div class="p-4"><x-td.pagination :paginator="$packages" label="Packages pages" /></div>@endif
            @endif
        </x-td.panel>
    @elseif ($view === 'groups')
        <div class="td-panels td-panels--2">
            <x-td.panel title="Scheduled group lessons">
                @if ($list->isEmpty())
                    <x-td.state kind="empty" title="No group lessons scheduled">Schedule a time from a group offering; learners then take seats until it is full. Each seat is priced and paid on its own.</x-td.state>
                @else
                    <ul class="m-0 list-none p-0">
                        @foreach ($list as $s)
                            @include('teach.partials.booking-row', ['s' => $s, 'tz' => $tz])
                            @if ($s->participants->isNotEmpty())
                                <li class="pb-3 text-sm text-td-ink-2">Seats: {{ $s->participants->map(fn ($p) => \App\Domain\Teach\Support\TutorContext::learnerName($p->learner).' ('.str_replace('_', ' ', $p->status).')')->implode(', ') }}</li>
                            @endif
                        @endforeach
                    </ul>
                    <x-td.pagination :paginator="$list" label="Group lesson pages" />
                @endif
            </x-td.panel>
            <x-td.panel title="Schedule a group lesson">
                @if ($groupOfferings->isEmpty())
                    <x-td.state kind="empty" title="No group offering yet">Create a group offering first: it sets the price per seat, the length and how many learners fit.
                        <x-slot:actions><x-td.button href="{{ route('teach.offerings.create', ['type' => 'group']) }}" size="sm">Create a group offering</x-td.button></x-slot:actions>
                    </x-td.state>
                @else
                    @foreach ($groupOfferings as $o)
                        <form method="POST" action="{{ route('teach.bookings.group', $o) }}" class="grid gap-3 border-t border-td-line pt-4 first:border-t-0 first:pt-0">
                            @csrf
                            <input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                            <p class="m-0 font-bold">{{ $o->title }} <span class="font-normal text-td-ink-2">{{ $o->capacity }} seats, {{ $o->duration_minutes }} min</span></p>
                            <x-td.field label="Subject" for="g{{ $o->id }}-subject"><x-td.select name="subject_id" id="g{{ $o->id }}-subject" :options="$o->subjects->pluck('name', 'id')->all()" /></x-td.field>
                            <div class="grid grid-cols-2 gap-3">
                                <x-td.field label="Date" for="g{{ $o->id }}-date"><x-td.input name="date" id="g{{ $o->id }}-date" type="date" min="{{ now($tz)->format('Y-m-d') }}" required /></x-td.field>
                                <x-td.field label="Start ({{ $tz }})" for="g{{ $o->id }}-time"><x-td.input name="time" id="g{{ $o->id }}-time" type="time" step="900" required /></x-td.field>
                            </div>
                            <div><x-td.button type="submit" variant="primary" size="sm">Schedule</x-td.button></div>
                        </form>
                    @endforeach
                @endif
            </x-td.panel>
        </div>
    @else
        @if ($view === 'requests' && $series->isNotEmpty())
            <x-td.panel title="Weekly series to accept" class="mb-6">
                <ul class="m-0 grid list-none gap-3 p-0">
                    @foreach ($series as $sr)
                        @php $pending = $sr->sessions->where('status', 'pending'); @endphp
                        <li class="flex flex-wrap items-center justify-between gap-3">
                            <span>{{ $pending->count() }} weekly lessons from {{ $pending->first()?->starts_at->copy()->timezone($tz)->format('D j M, H:i') }}</span>
                            <form method="POST" action="{{ route('teach.bookings.series.accept', $sr) }}">@csrf<x-td.button type="submit" variant="primary" size="sm">Accept all {{ $pending->count() }}</x-td.button></form>
                        </li>
                    @endforeach
                </ul>
                <p class="mb-0 mt-3 text-sm text-td-ink-2">Weeks that clashed with your calendar were skipped when the learner booked. You can also answer each week on its own below.</p>
            </x-td.panel>
        @endif
        @if ($view === 'waiting' && $guardianHolds > 0)
            <x-td.notice class="mb-6">{{ $guardianHolds }} {{ \Illuminate\Support\Str::plural('request', $guardianHolds) }} from a learner under 18 {{ $guardianHolds === 1 ? 'waits' : 'wait' }} for a guardian. You see who and when once a guardian approves.</x-td.notice>
        @endif
        <x-td.panel :title="collect($tabs)->firstWhere('current', true)['label']">
            @if ($list->isEmpty())
                <x-td.state kind="empty" :title="$empty[$view][0]">{{ $empty[$view][1] }}</x-td.state>
            @else
                <ul class="m-0 list-none p-0">
                    @foreach ($list as $s)
                        @include('teach.partials.booking-row', ['s' => $s, 'tz' => $tz])
                    @endforeach
                </ul>
                @if ($list instanceof \Illuminate\Contracts\Pagination\Paginator)
                    <x-td.pagination :paginator="$list" label="Booking pages" />
                @endif
            @endif
        </x-td.panel>
    @endif
</x-layouts::workspace>
