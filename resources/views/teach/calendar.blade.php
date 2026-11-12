@php
    $weekdays = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
    $lessonCount = collect($days)->sum(fn ($d) => $d['lessons']->count());
@endphp
<x-layouts::workspace title="Calendar">
    <x-td.page-head title="Calendar" :lede="'Week of '.$start->format('j F Y').', times in '.$tz.'. '.$lessonCount.' '.\Illuminate\Support\Str::plural('lesson', $lessonCount).' this week.'">
        <x-slot:actions>
            <x-td.button href="{{ route('teach.calendar', ['week' => $prev]) }}" icon="chevron-left" icon-only>Previous week</x-td.button>
            <x-td.button href="{{ route('teach.calendar') }}">This week</x-td.button>
            <x-td.button href="{{ route('teach.calendar', ['week' => $next]) }}" icon="chevron-right" icon-only>Next week</x-td.button>
        </x-slot:actions>
    </x-td.page-head>

    <section aria-label="Week" class="grid gap-px border border-td-line bg-td-line md:grid-cols-7">
        @foreach ($days as $day)
            <div @class(['grid content-start gap-2 p-3 md:min-h-40', 'bg-td-surface' => ! $day['blocked'], 'bg-td-sunken' => (bool) $day['blocked']]) @if ($day['today']) aria-current="date" @endif>
                <h2 class="m-0 text-sm font-bold">
                    <span @class(['text-td-primary' => $day['today']])>{{ $day['date']->format('D j M') }}</span>
                    @if ($day['today'])<span class="font-normal text-td-ink-2"> today</span>@endif
                </h2>
                @if ($day['blocked'])
                    <p class="m-0 text-sm text-td-ink-2"><x-td.icon name="calendar" /> Day off{{ $day['blocked']->reason ? ': '.$day['blocked']->reason : '' }}</p>
                @elseif ($day['windows']->isEmpty())
                    <p class="m-0 text-sm text-td-ink-2">Closed</p>
                @else
                    <p class="m-0 text-sm text-td-ink-2">Open {{ $day['windows']->map(fn ($w) => substr($w->start_time, 0, 5).' to '.substr($w->end_time, 0, 5))->implode(', ') }}</p>
                @endif
                @foreach ($day['lessons'] as $s)
                    @php $st = \App\Enums\SessionStatus::from($s->status); @endphp
                    <a href="{{ route('teach.bookings', ['view' => $st === \App\Enums\SessionStatus::Pending ? 'requests' : ($st->value === 'awaiting_payment' ? 'waiting' : ($s->isGroup() ? 'groups' : 'upcoming'))]) }}#booking-{{ $s->id }}"
                       @class([
                           'block rounded-td border px-2 py-1 text-sm no-underline text-td-ink',
                           'border-td-primary bg-td-primary-soft' => in_array($st->value, ['confirmed', 'in_progress'], true),
                           'border-dashed border-td-edge bg-td-surface' => in_array($st->value, ['pending', 'awaiting_payment'], true),
                           'border-td-line bg-td-sunken' => in_array($st->value, ['completed', 'no_show', 'disputed'], true),
                       ])>
                        <span class="block font-bold tabular-nums">{{ $s->starts_at->copy()->timezone($tz)->format('H:i') }}, {{ $s->duration_minutes }} min</span>
                        <span class="block">{{ $s->isGroup() ? 'Group: '.$s->seats_taken.' of '.$s->capacity : \App\Domain\Teach\Support\TutorContext::learnerName($s->student) }}</span>
                        <span class="block text-td-ink-2">{{ $s->subject?->name }} · {{ $st === \App\Enums\SessionStatus::Pending ? 'Request' : $st->label() }}</span>
                    </a>
                @endforeach
            </div>
        @endforeach
    </section>
    <p class="mt-2 text-sm text-td-ink-2">Solid: confirmed. Dashed: a request, or waiting for payment. Grey: finished.</p>

    <div class="td-panels mt-6 md:grid-cols-2">
        <x-td.panel title="Weekly open hours">
            @if ($allWindows->isEmpty())
                <x-td.state kind="empty" title="No open hours">Learners can request lessons only inside your open hours. Add the times you can teach each week.</x-td.state>
            @else
                <ul class="m-0 grid list-none gap-2 p-0">
                    @foreach ($allWindows as $w)
                        <li class="flex flex-wrap items-center justify-between gap-2">
                            <span>{{ $weekdays[$w->weekday] ?? $w->weekday }} {{ substr($w->start_time, 0, 5) }} to {{ substr($w->end_time, 0, 5) }}</span>
                            <form method="POST" action="{{ route('teach.calendar.hours.destroy', $w->id) }}">@csrf @method('DELETE')<x-td.button type="submit" variant="quiet" size="sm" icon="x">Remove<span class="td-sr"> {{ $weekdays[$w->weekday] ?? '' }} {{ substr($w->start_time, 0, 5) }}</span></x-td.button></form>
                        </li>
                    @endforeach
                </ul>
            @endif
            <form method="POST" action="{{ route('teach.calendar.hours.store') }}" class="mt-4 grid gap-3 border-t border-td-line pt-4 sm:grid-cols-[1fr_1fr_1fr_auto] sm:items-end">
                @csrf
                <x-td.field label="Day" for="weekday"><x-td.select name="weekday" :options="$weekdays" /></x-td.field>
                <x-td.field label="From" for="start_time"><x-td.input name="start_time" type="time" step="900" required /></x-td.field>
                <x-td.field label="To" for="end_time"><x-td.input name="end_time" type="time" step="900" required /></x-td.field>
                <x-td.button type="submit" variant="primary">Add hours</x-td.button>
            </form>
        </x-td.panel>
        <x-td.panel title="Days off">
            @if ($upcomingBlocked->isEmpty())
                <p class="m-0 text-td-ink-2">No days off coming up.</p>
            @else
                <ul class="m-0 grid list-none gap-2 p-0">
                    @foreach ($upcomingBlocked as $b)
                        @php $bd = $b->date instanceof \DateTimeInterface ? $b->date->format('Y-m-d') : substr((string) $b->date, 0, 10); @endphp
                        <li class="flex flex-wrap items-center justify-between gap-2">
                            <span>{{ \Carbon\CarbonImmutable::parse($bd)->format('D j M Y') }}{{ $b->reason ? ', '.$b->reason : '' }}</span>
                            <form method="POST" action="{{ route('teach.calendar.off.destroy', $b->id) }}">@csrf @method('DELETE')<x-td.button type="submit" variant="quiet" size="sm" icon="x">Remove<span class="td-sr"> {{ $bd }}</span></x-td.button></form>
                        </li>
                    @endforeach
                </ul>
            @endif
            <form method="POST" action="{{ route('teach.calendar.off.store') }}" class="mt-4 grid gap-3 border-t border-td-line pt-4 sm:grid-cols-[1fr_2fr_auto] sm:items-end">
                @csrf
                <x-td.field label="Date" for="date"><x-td.input name="date" type="date" min="{{ now($tz)->format('Y-m-d') }}" required /></x-td.field>
                <x-td.field label="Note for yourself" for="reason" optional><x-td.input name="reason" maxlength="255" /></x-td.field>
                <x-td.button type="submit">Add day off</x-td.button>
            </form>
            <p class="mb-0 mt-3 text-sm text-td-ink-2">A day off stops new bookings. Lessons already booked that day stay booked: cancel them from Requests and bookings if you must.</p>
        </x-td.panel>
    </div>
</x-layouts::workspace>
