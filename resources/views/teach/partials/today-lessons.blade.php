{{-- Part of teach/home (Today); included with the parent's variables. --}}
<div class="td-panels td-panels--2">
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
