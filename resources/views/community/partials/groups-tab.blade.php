{{-- Study groups in a room; for a tutor room also its workshops, requests and members. --}}
@php
    use App\Domain\Community\Models\Room;
    use App\Domain\Community\Support\Names;
    $tz = config('community.timezone');
@endphp
@if ($room->isTutorRoom())
    <div class="td-panel__head"><h2 class="td-panel__title">Workshops</h2></div>
    @if ($workshops->isEmpty())
        <x-td.state kind="empty" title="No workshops scheduled">{{ $canManage ? 'Schedule the first one below. Members can add it to their calendar.' : 'The tutor has not scheduled a workshop yet.' }}</x-td.state>
    @else
        <ul class="m-0 list-none p-0">
            @foreach ($workshops as $w)
                <li class="flex flex-wrap items-baseline justify-between gap-2 border-b border-td-line px-5 py-3 last:border-b-0">
                    <span><strong>{{ $w->title }}</strong>@if ($w->cancelled_at) <span class="td-badge td-badge--lapsed">Cancelled</span>@endif
                        <span class="block text-sm text-td-ink-2"><time datetime="{{ $w->starts_at->toIso8601String() }}">{{ $w->localStart()->format('D j M Y, H:i') }}</time> ({{ $w->timezone }}), {{ $w->duration_minutes }} minutes @if ($w->where_note) · {{ $w->where_note }} @endif</span></span>
                    <x-td.button :href="route('rooms.sessions.calendar', $w)" variant="quiet" size="sm" icon="calendar">Add to calendar</x-td.button>
                </li>
            @endforeach
        </ul>
    @endif
    @if ($canManage)
        <details class="border-t border-td-line px-5 py-4">
            <summary class="cursor-pointer font-semibold">Schedule a workshop</summary>
            <form method="POST" action="{{ route('rooms.sessions.store', $room) }}" class="mt-4 grid max-w-xl gap-4">
                @csrf
                <x-td.field label="Title" for="w-title"><x-td.input name="title" id="w-title" maxlength="160" required /></x-td.field>
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-td.field label="Starts" for="w-start"><x-td.input type="datetime-local" name="starts_at" id="w-start" required /></x-td.field>
                    <x-td.field label="Minutes" for="w-duration"><x-td.input type="number" name="duration_minutes" id="w-duration" value="60" min="15" max="480" required /></x-td.field>
                </div>
                <input type="hidden" name="timezone" value="{{ $tz }}">
                <x-td.field label="Where" for="w-where" optional hint="Times are in {{ $tz }}. Say where, or how members will meet."><x-td.input name="where_note" id="w-where" maxlength="200" :hint="true" /></x-td.field>
                <div><x-td.button type="submit" variant="primary">Schedule</x-td.button></div>
            </form>
        </details>

        <div class="border-t border-td-line">
            <div class="td-panel__head"><h2 class="td-panel__title">Members</h2>
                @if ($room->join_rule === Room::INVITE)
                    <form method="POST" action="{{ route('rooms.invite-code', $room) }}">@csrf<x-td.button type="submit" variant="quiet" size="sm">New invitation code</x-td.button></form>
                @endif
            </div>
            @if ($room->join_rule === Room::INVITE)
                <p class="mx-5 mt-0 text-sm">Invitation code: <strong class="tabular-nums">{{ $room->invite_code }}</strong>. Share it with the learners you invite.</p>
            @endif
            @foreach ($pending as $p)
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-td-line px-5 py-3">
                    <span>{{ Names::short($p->user) }} asked to join</span>
                    <span class="flex gap-2">
                        <form method="POST" action="{{ route('rooms.members.decide', [$room, $p]) }}">@csrf<input type="hidden" name="accept" value="1"><x-td.button type="submit" size="sm" variant="primary">Accept</x-td.button></form>
                        <form method="POST" action="{{ route('rooms.members.decide', [$room, $p]) }}">@csrf<input type="hidden" name="accept" value="0"><x-td.button type="submit" size="sm" variant="secondary">Decline</x-td.button></form>
                    </span>
                </div>
            @endforeach
            <ul class="m-0 list-none p-0">
                @foreach ($roster as $m)
                    @continue($m->role === 'owner')
                    <li class="flex items-center justify-between gap-2 border-b border-td-line px-5 py-2 last:border-b-0">
                        <span>{{ Names::short($m->user) }}</span>
                        <form method="POST" action="{{ route('rooms.members.remove', [$room, $m]) }}">@csrf @method('DELETE')<x-td.button type="submit" size="sm" variant="quiet">Remove</x-td.button></form>
                    </li>
                @endforeach
            </ul>
            <form method="POST" action="{{ route('rooms.archive', $room) }}" class="px-5 py-4">@csrf<x-td.button type="submit" variant="danger" size="sm">Archive this room</x-td.button></form>
        </div>
    @endif
    <div class="border-t border-td-line"></div>
@endif

<div class="td-panel__head"><h2 class="td-panel__title">Study groups</h2></div>
@if ($groups->isEmpty())
    <x-td.state kind="empty" title="No study groups yet">Start one for a topic or an exam paper. You choose the size and who can join.</x-td.state>
@else
    <ul class="m-0 list-none p-0" aria-label="Study groups">
        @foreach ($groups as $g)
            @php($next = $nextSessions[$g->id] ?? null)
            <li class="grid gap-1 border-b border-td-line px-5 py-4 last:border-b-0">
                <a class="td-link text-lg" href="{{ route('rooms.groups.show', $g) }}">{{ $g->name }}</a>
                <p class="m-0 text-sm text-td-ink-2">
                    {{ collect([$g->topic, $g->exam_focus])->filter()->implode(' · ') ?: 'No topic given' }}
                </p>
                <p class="m-0 text-sm text-td-ink-2 tabular-nums">{{ $g->members_count }} of {{ $g->size_cap }} places · {{ $g->joinRuleLabel() }}
                    @if ($next) · next session {{ $next->localStart()->format('D j M, H:i') }} @endif</p>
            </li>
        @endforeach
    </ul>
@endif

@if ($canParticipate)
    <details class="border-t border-td-line px-5 py-4" @if ($errors->hasAny(['name', 'size_cap', 'join_rule'])) open @endif>
        <summary class="cursor-pointer font-semibold">Start a study group</summary>
        <form method="POST" action="{{ route('rooms.groups.store', $room) }}" class="mt-4 grid max-w-xl gap-4">
            @csrf
            <x-td.field label="Group name" for="g-name" :error="$errors->first('name')"><x-td.input name="name" id="g-name" maxlength="120" required /></x-td.field>
            <x-td.field label="Topic" for="g-topic" optional><x-td.input name="topic" id="g-topic" maxlength="160" placeholder="Algebra and equations" /></x-td.field>
            <x-td.field label="Exam focus" for="g-exam" optional><x-td.input name="exam_focus" id="g-exam" maxlength="160" placeholder="November Paper 1" /></x-td.field>
            <x-td.field label="When you usually meet" for="g-schedule" optional><x-td.input name="schedule_note" id="g-schedule" maxlength="200" placeholder="Tuesdays after school" /></x-td.field>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-td.field label="Size limit" for="g-size" :error="$errors->first('size_cap')"><x-td.input type="number" name="size_cap" id="g-size" value="6" min="{{ config('community.group_size.min') }}" max="{{ config('community.group_size.max') }}" required /></x-td.field>
                <x-td.field label="Who can join" for="g-rule"><x-td.select name="join_rule" id="g-rule" :options="['open' => 'Anyone in the room', 'approval' => 'I accept each request', 'invite' => 'Only with an invitation code']" /></x-td.field>
            </div>
            <div><x-td.button type="submit" variant="primary" icon="users">Start the group</x-td.button></div>
        </form>
    </details>
@endif
