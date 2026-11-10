@php
    use App\Domain\Community\Models\GroupMember;
    use App\Domain\Community\Models\Room;
    use App\Domain\Community\Support\Names;
    $upcoming = $sessions->filter(fn ($s) => ! $s->cancelled_at && $s->endsAt()->isFuture());
    $past = $sessions->filter(fn ($s) => $s->hasStarted() && ! $s->cancelled_at)->sortByDesc('starts_at');
    $next = $upcoming->first();
    $openTasks = $tasks->filter(fn ($t) => $t->assignees->contains(fn ($a) => $a->done_at === null));
@endphp
<x-layouts::learner :title="$group->name" :step="$step">
    <nav aria-label="Breadcrumb" class="mb-3 text-sm">
        <a class="td-link" href="{{ route('rooms.show', [$room, 'tab' => 'groups']) }}">{{ $room->displayName() }}</a>
        <span class="text-td-ink-2">/ Study group</span>
    </nav>

    <x-td.page-head :title="$group->name" :lede="collect([$group->topic, $group->exam_focus])->filter()->implode(' · ') ?: 'A study group in '.$room->displayName().'.'">
        <x-slot:actions>
            @if (! $isMember && $membership?->status !== GroupMember::PENDING && $group->status === 'active')
                <form method="POST" action="{{ route('rooms.groups.join', $group) }}" class="flex flex-wrap items-end gap-2">
                    @csrf
                    @if ($group->join_rule === Room::INVITE)
                        <x-td.field label="Invitation code" for="code"><x-td.input name="code" id="code" maxlength="24" required autocomplete="off" /></x-td.field>
                    @endif
                    <x-td.button type="submit" variant="primary" icon="users" :disabled="$group->isFull()">{{ $group->isFull() ? 'Group is full' : ($group->join_rule === Room::APPROVAL ? 'Ask to join' : 'Join the group') }}</x-td.button>
                </form>
            @endif
        </x-slot:actions>
    </x-td.page-head>

    @if ($errors->has('community') || $errors->has('safety'))
        <x-td.notice tone="danger" class="mb-6">{{ $errors->first('community') ?: $errors->first('safety') }}</x-td.notice>
    @endif
    @if ($membership?->status === GroupMember::PENDING)
        <x-td.notice tone="info" class="mb-6">Your request is with {{ Names::short($group->owner) }}, who organises this group.</x-td.notice>
    @endif
    @if ($group->status !== 'active')
        <x-td.notice tone="warning" class="mb-6">This group is closed.</x-td.notice>
    @endif

    <div class="td-panels td-panels--2">
        <div class="td-panel">
            {{-- The group's one question: when do we next meet, and what is left to revise? --}}
            <div class="td-panel__head"><h2 class="td-panel__title">Next session</h2></div>
            <div class="td-panel__body">
                @if ($next)
                    <p class="m-0 font-td-display text-2xl font-bold" style="font-stretch: var(--td-display-stretch)"><time datetime="{{ $next->starts_at->toIso8601String() }}">{{ $next->localStart()->format('l j F, H:i') }}</time></p>
                    <p class="mt-1 mb-3 text-td-ink-2">{{ $next->title }} · {{ $next->duration_minutes }} minutes · {{ $next->timezone }} @if ($next->where_note) · {{ $next->where_note }} @endif</p>
                    <div class="flex flex-wrap gap-2">
                        <x-td.button :href="route('rooms.sessions.calendar', $next)" variant="secondary" size="sm" icon="calendar">Add to my calendar</x-td.button>
                        @if ($isOrganiser)
                            <form method="POST" action="{{ route('rooms.sessions.cancel', $next) }}">@csrf<x-td.button type="submit" variant="quiet" size="sm">Cancel this session</x-td.button></form>
                        @endif
                    </div>
                @else
                    <p class="m-0 text-td-ink-2">No session is scheduled. {{ $group->schedule_note ? 'Usually: '.$group->schedule_note.'.' : '' }}</p>
                @endif
                @if ($upcoming->count() > 1)
                    <ul class="mt-4 mb-0 list-none p-0 text-sm">
                        @foreach ($upcoming->skip(1) as $s)
                            <li class="flex flex-wrap justify-between gap-2 border-t border-td-line py-2"><span>{{ $s->localStart()->format('D j M, H:i') }} · {{ $s->title }}</span><a class="td-link" href="{{ route('rooms.sessions.calendar', $s) }}">Calendar file</a></li>
                        @endforeach
                    </ul>
                @endif
                @if ($isOrganiser)
                    <details class="mt-4" @if ($errors->hasAny(['starts_at', 'title', 'duration_minutes'])) open @endif>
                        <summary class="cursor-pointer font-semibold">Schedule a session</summary>
                        <form method="POST" action="{{ route('rooms.groups.sessions.store', $group) }}" class="mt-3 grid max-w-xl gap-4">
                            @csrf
                            <x-td.field label="What you will work on" for="s-title" :error="$errors->first('title')"><x-td.input name="title" id="s-title" maxlength="160" required /></x-td.field>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <x-td.field label="Starts" for="s-start" :error="$errors->first('starts_at')"><x-td.input type="datetime-local" name="starts_at" id="s-start" required /></x-td.field>
                                <x-td.field label="Minutes" for="s-duration"><x-td.input type="number" name="duration_minutes" id="s-duration" value="60" min="15" max="480" required /></x-td.field>
                            </div>
                            <input type="hidden" name="timezone" value="{{ $timezone }}">
                            <x-td.field label="Where" for="s-where" optional hint="Times are in {{ $timezone }}."><x-td.input name="where_note" id="s-where" maxlength="200" :hint="true" /></x-td.field>
                            <div><x-td.button type="submit" variant="primary" icon="calendar">Schedule</x-td.button></div>
                        </form>
                    </details>
                @endif
            </div>

            @if ($isMember)
                <section class="border-t border-td-line" aria-labelledby="revision-title">
                    <div class="td-panel__head"><h2 class="td-panel__title" id="revision-title">Revision list</h2>
                        <span class="text-sm text-td-ink-2">Tick what you have revised. Everyone sees who has.</span></div>
                    @if ($revision->isEmpty())
                        <p class="mx-5 mt-0 text-td-ink-2">No topics on the list yet.</p>
                    @else
                        <ul class="m-0 list-none p-0">
                            @foreach ($revision as $item)
                                @php($done = $item->ticks->contains('user_id', auth()->id()))
                                <li class="flex flex-wrap items-center justify-between gap-2 border-t border-td-line px-5 py-3">
                                    <form method="POST" action="{{ route('rooms.revision.tick', $item) }}" class="flex items-center gap-3">
                                        @csrf
                                        <input type="hidden" name="done" value="{{ $done ? 0 : 1 }}">
                                        <button type="submit" class="td-btn td-btn--icon td-btn--sm" aria-pressed="{{ $done ? 'true' : 'false' }}" aria-label="{{ $done ? 'Untick ' : 'Tick ' }}{{ $item->title }}"><x-td.icon :name="$done ? 'circle-check' : 'circle-dashed'" /></button>
                                        <span>{{ $item->title }}</span>
                                    </form>
                                    <span class="text-sm text-td-ink-2">{{ $item->ticks->isEmpty() ? 'Nobody yet' : 'Revised by '.$item->ticks->map(fn ($t) => Names::short($t->user))->implode(', ') }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    <form method="POST" action="{{ route('rooms.groups.revision.store', $group) }}" class="flex flex-wrap items-end gap-3 border-t border-td-line px-5 py-4">
                        @csrf
                        <x-td.field label="Add a topic" for="r-title" class="min-w-0 flex-1"><x-td.input name="title" id="r-title" maxlength="160" required /></x-td.field>
                        <x-td.button type="submit" variant="secondary" icon="plus">Add</x-td.button>
                    </form>
                </section>

                <section class="border-t border-td-line" aria-labelledby="tasks-title">
                    <div class="td-panel__head"><h2 class="td-panel__title" id="tasks-title">Group tasks</h2>
                        <span class="text-sm text-td-ink-2 tabular-nums">{{ $openTasks->count() }} still open</span></div>
                    @forelse ($tasks as $task)
                        <article class="grid gap-2 border-t border-td-line px-5 py-3">
                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                <strong>{{ $task->title }}</strong>
                                <span class="text-sm text-td-ink-2">{{ $task->due_on ? 'Due '.$task->due_on->format('j M') : 'No due date' }}</span>
                            </div>
                            @if ($task->details)<p class="m-0 whitespace-pre-line text-sm">{{ $task->details }}</p>@endif
                            <ul class="m-0 list-none p-0 text-sm">
                                @foreach ($task->assignees as $a)
                                    <li class="flex flex-wrap items-center gap-2 py-1">
                                        <x-td.icon :name="$a->done_at ? 'circle-check' : 'circle-dashed'" />
                                        <span>{{ Names::short($a->user) }}{{ $a->done_at ? ': done'.($a->done_note ? ' ('.$a->done_note.')' : '') : ': not done yet' }}</span>
                                        @if ($a->user_id === auth()->id())
                                            <form method="POST" action="{{ route('rooms.tasks.mark', $task) }}" class="flex flex-wrap items-center gap-2">
                                                @csrf
                                                <input type="hidden" name="done" value="{{ $a->done_at ? 0 : 1 }}">
                                                @unless ($a->done_at)<label class="td-sr" for="note-{{ $task->id }}">What you did</label><input class="td-input" style="min-height:2rem;width:12rem" name="note" id="note-{{ $task->id }}" maxlength="200" placeholder="What you did">@endunless
                                                <x-td.button type="submit" size="sm" variant="quiet">{{ $a->done_at ? 'Mark not done' : 'Mark done' }}</x-td.button>
                                            </form>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </article>
                    @empty
                        <p class="mx-5 mt-0 text-td-ink-2">No tasks yet. Split a past paper between you, or give each person a topic to explain.</p>
                    @endforelse
                    <details class="border-t border-td-line px-5 py-4">
                        <summary class="cursor-pointer font-semibold">Add a task</summary>
                        <form method="POST" action="{{ route('rooms.groups.tasks.store', $group) }}" class="mt-3 grid max-w-xl gap-4">
                            @csrf
                            <x-td.field label="Task" for="t-title"><x-td.input name="title" id="t-title" maxlength="160" required /></x-td.field>
                            <x-td.field label="Details" for="t-details" optional><x-td.textarea name="details" id="t-details" rows="2" maxlength="2000" /></x-td.field>
                            <x-td.field label="Due" for="t-due" optional><x-td.input type="date" name="due_on" id="t-due" /></x-td.field>
                            <fieldset class="grid gap-2"><legend class="td-label">Who does it</legend>
                                @foreach ($members as $m)
                                    <x-td.check name="assignees[]" :id="'assignee-'.$m->user_id" :value="$m->user_id">{{ Names::short($m->user) }}</x-td.check>
                                @endforeach
                            </fieldset>
                            <div><x-td.button type="submit" variant="primary">Add the task</x-td.button></div>
                        </form>
                    </details>
                </section>

                <section class="border-t border-td-line" aria-labelledby="resources-title">
                    <div class="td-panel__head"><h2 class="td-panel__title" id="resources-title">Shared resources</h2></div>
                    @forelse ($resources as $r)
                        <div class="border-t border-td-line px-5 py-3">
                            @if ($r->resource)<a class="td-link" href="{{ route('learning.library.show', $r->library_resource_id) }}">{{ $r->resource->title }}</a>@else<span class="text-td-ink-2">No longer in the library</span>@endif
                            <p class="m-0 text-sm text-td-ink-2">Shared by {{ Names::short($r->addedBy) }}@if ($r->note) · {{ $r->note }} @endif</p>
                        </div>
                    @empty
                        <p class="mx-5 mt-0 text-td-ink-2">Nothing shared yet.</p>
                    @endforelse
                    @if ($myResources->isNotEmpty())
                        <form method="POST" action="{{ route('rooms.groups.resources.store', $group) }}" class="grid gap-3 border-t border-td-line px-5 py-4 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-end">
                            @csrf
                            <x-td.field label="Library item" for="res-id"><x-td.select name="library_resource_id" id="res-id" :options="$myResources->mapWithKeys(fn ($x) => [$x->id => $x->title])->all()" /></x-td.field>
                            <x-td.field label="Note" for="res-note" optional><x-td.input name="note" id="res-note" maxlength="200" /></x-td.field>
                            <x-td.button type="submit" variant="secondary">Share</x-td.button>
                        </form>
                    @endif
                </section>
            @else
                <div class="border-t border-td-line px-5 py-4 text-td-ink-2">The revision list, tasks and shared resources are for group members.</div>
            @endif
        </div>

        <aside class="td-panel" aria-labelledby="members-title">
            <div class="td-panel__head"><h2 class="td-panel__title" id="members-title">Members</h2><span class="text-sm text-td-ink-2 tabular-nums">{{ $group->members_count }} of {{ $group->size_cap }}</span></div>
            <ul class="m-0 list-none p-0">
                @foreach ($members as $m)
                    <li class="flex items-center justify-between gap-2 border-t border-td-line px-5 py-2">
                        <span class="flex items-center gap-2"><x-td.avatar :name="Names::short($m->user)" size="sm" decorative />{{ Names::short($m->user) }}@if ($m->role === 'owner')<span class="text-sm text-td-ink-2">organiser</span>@endif</span>
                        @if ($isOrganiser && $m->role !== 'owner')
                            <form method="POST" action="{{ route('rooms.groups.members.remove', [$group, $m]) }}">@csrf @method('DELETE')<x-td.button type="submit" size="sm" variant="quiet">Remove</x-td.button></form>
                        @endif
                    </li>
                @endforeach
            </ul>
            @foreach ($pending as $p)
                <div class="flex flex-wrap items-center justify-between gap-2 border-t border-td-line px-5 py-2">
                    <span>{{ Names::short($p->user) }} asked to join</span>
                    <span class="flex gap-2">
                        <form method="POST" action="{{ route('rooms.groups.members.decide', [$group, $p]) }}">@csrf<input type="hidden" name="accept" value="1"><x-td.button type="submit" size="sm" variant="primary">Accept</x-td.button></form>
                        <form method="POST" action="{{ route('rooms.groups.members.decide', [$group, $p]) }}">@csrf<input type="hidden" name="accept" value="0"><x-td.button type="submit" size="sm" variant="secondary">Decline</x-td.button></form>
                    </span>
                </div>
            @endforeach
            <div class="grid gap-3 border-t border-td-line px-5 py-4 text-sm">
                <p class="m-0 text-td-ink-2">{{ $group->joinRuleLabel() }}.</p>
                @if ($isOrganiser && $group->join_rule === Room::INVITE)
                    <p class="m-0">Invitation code: <strong class="tabular-nums">{{ $group->invite_code }}</strong></p>
                @endif
                @if ($isOrganiser && $past->isNotEmpty())
                    @php($last = $past->first())
                    <form method="POST" action="{{ route('rooms.sessions.attendance', $last) }}" class="grid gap-2">
                        @csrf
                        <fieldset class="grid gap-1"><legend class="td-label">Who came to {{ $last->localStart()->format('D j M') }}?</legend>
                            @foreach ($members as $m)
                                <x-td.check name="attended[]" :id="'attended-'.$m->user_id" :value="$m->user_id" :checked="$last->attendance->contains('user_id', $m->user_id)">{{ Names::short($m->user) }}</x-td.check>
                            @endforeach
                        </fieldset>
                        <div><x-td.button type="submit" size="sm" variant="secondary">Save attendance</x-td.button></div>
                    </form>
                @endif
                @if ($isMember && ! $isOrganiser)
                    <form method="POST" action="{{ route('rooms.groups.leave', $group) }}">@csrf<x-td.button type="submit" size="sm" variant="quiet" icon="log-out">Leave the group</x-td.button></form>
                @elseif ($isOrganiser && $group->status === 'active')
                    <form method="POST" action="{{ route('rooms.groups.close', $group) }}">@csrf<x-td.button type="submit" size="sm" variant="quiet">Close the group</x-td.button></form>
                @endif
            </div>
        </aside>
    </div>
</x-layouts::learner>
