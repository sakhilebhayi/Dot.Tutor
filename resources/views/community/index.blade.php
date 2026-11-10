@php
    use App\Domain\Community\Models\Room;
    $level = $mine['level'];
    $waiting = $mine['hub']->sum('open_questions');
@endphp
<x-layouts::learner title="My rooms" :step="$step">
    <x-td.page-head title="My rooms"
        :lede="$level
            ? 'Your subject rooms for '.$level->name.', '.($level->curriculum?->short_name ?: $level->curriculum?->name).'. Ask a question, answer one, share what helped you.'
            : 'Rooms are organised by curriculum, level and subject, so everyone in a room is learning the same thing.'">
        <x-slot:actions>
            <x-td.button href="#browse" variant="secondary" icon="layers">Other levels</x-td.button>
        </x-slot:actions>
    </x-td.page-head>

    @if ($audience === Room::UNDER_18)
        <x-td.notice tone="info" title="Rooms for learners under 18" class="mb-6">
            Only learners under 18, tutors who passed a safeguarding screening, and moderators can enter these rooms.
            Phone numbers, email addresses and social media handles are hidden in posts.
        </x-td.notice>
    @endif

    <div class="td-panels td-panels--2">
        @if ($level)
            <x-td.panel :title="$level->name.', '.($level->curriculum?->short_name ?: $level->curriculum?->name)" flush>
                <x-slot:actions>
                    <x-td.button :href="route('rooms.levels.show', $level)" variant="quiet" size="sm" icon-end="arrow-right">All subjects</x-td.button>
                </x-slot:actions>
                @if ($mine['hub']->isEmpty())
                    <x-td.state kind="empty" title="No subjects are listed for this level yet">
                        An academic editor adds subjects to each level. Browse the level to see what is offered.
                    </x-td.state>
                @else
                    <p class="m-0 border-b border-td-line px-5 py-3 text-sm text-td-ink-2">
                        @if ($waiting > 0)
                            <strong class="text-td-ink">{{ trans_choice('{1} 1 question is|[2,*] :count questions are', $waiting) }} waiting for an answer</strong> in your subjects.
                        @else
                            No questions are waiting for an answer in your subjects right now.
                        @endif
                    </p>
                    <ul class="m-0 list-none p-0" aria-label="Your subject rooms">
                        @foreach ($mine['hub'] as $row)
                            <li class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1 border-b border-td-line px-5 py-4 last:border-b-0">
                                <a class="td-link text-lg" href="{{ route('rooms.open', [$level, $row['offering'], 'audience' => $audience]) }}">{{ $row['offering']->subject->name }}</a>
                                <span class="text-sm text-td-ink-2 tabular-nums">
                                    @if ($row['room'] && $row['last_activity_at'])
                                        {{ $row['open_questions'] }} waiting · {{ $row['resolved_questions'] }} resolved · active {{ \Illuminate\Support\Carbon::parse($row['last_activity_at'])->diffForHumans() }}
                                    @else
                                        No posts yet. Be the first to ask.
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-td.panel>
        @else
            <x-td.panel title="Your level">
                <x-td.state kind="empty" title="Tell us your grade or level first">
                    Your rooms come from your learner profile: country, curriculum, grade or level, and subjects.
                    <x-slot:actions>
                        @if (Route::has('academic.profile.edit'))
                            <x-td.button :href="route('academic.profile.edit')" variant="primary" icon="school">Set up my learner profile</x-td.button>
                        @endif
                        <x-td.button href="#browse" variant="secondary">Browse levels</x-td.button>
                    </x-slot:actions>
                </x-td.state>
            </x-td.panel>
        @endif

        <x-td.panel title="Your learning here">
            <x-td.stat-strip label="Your learning indicators" :items="[
                ['label' => 'Questions resolved', 'value' => $indicators['questions_resolved'], 'hint' => 'Questions you asked that were answered'],
                ['label' => 'Answers accepted', 'value' => $indicators['answers_accepted'], 'hint' => 'Your answers that solved someone\'s question'],
                ['label' => 'Resources shared', 'value' => $indicators['resources_contributed']],
                ['label' => 'Study sessions attended', 'value' => $indicators['sessions_attended']],
            ]" />
            @php($reached = collect($indicators['milestones'])->where('reached', true))
            @php($next = collect($indicators['milestones'])->firstWhere('reached', false))
            <div class="mt-5 grid gap-2 text-sm">
                <p class="td-label m-0">Milestones</p>
                @forelse ($reached as $m)
                    <p class="m-0 flex items-center gap-2"><x-td.icon name="circle-check" class="text-td-success" />{{ $m['text'] }}</p>
                @empty
                    <p class="m-0 text-td-ink-2">None yet. Asking your first question is a good start.</p>
                @endforelse
                @if ($next)
                    <p class="m-0 flex items-center gap-2 text-td-ink-2"><x-td.icon name="circle-dashed" />Next: {{ lcfirst($next['text']) }}</p>
                @endif
            </div>
            <p class="mt-5 mb-0 text-sm text-td-ink-2">Counted from what you did in rooms. There are no likes, followers or rankings here.</p>
        </x-td.panel>
    </div>

    <div class="mt-8">
        <x-td.panel title="Rooms and tutor rooms you joined" flush>
            @if ($mine['joined']->isEmpty())
                <x-td.state kind="empty" title="You have not joined a room yet">
                    Open a subject above and join it, or post a question: posting joins the room for you.
                </x-td.state>
            @else
                <x-td.table caption="Rooms you joined" caption-hidden>
                    <thead><tr><th>Room</th><th>Level</th><th class="is-num">Waiting</th><th>Your place</th></tr></thead>
                    <tbody>
                        @foreach ($mine['joined'] as $room)
                            @php($membership = $room->members->first())
                            <tr>
                                <td><a class="td-link" href="{{ route('rooms.show', $room) }}">{{ $room->displayName() }}</a>@if ($room->isTutorRoom())<span class="block text-sm text-td-ink-2">Tutor room by {{ \App\Domain\Community\Support\Names::short($room->owner) }}</span>@endif</td>
                                <td><x-td.pill kind="level" :stage="$room->level?->stage?->code">{{ $room->level?->name }}</x-td.pill></td>
                                <td class="is-num">{{ $counts[$room->id]['open'] ?? 0 }}</td>
                                <td>{{ $membership?->statusLabel() ?? 'Member' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-td.table>
            @endif
        </x-td.panel>
    </div>

    <section id="browse" class="mt-8" aria-labelledby="browse-title">
        <x-td.panel title="Browse other levels" heading-level="2">
            <p class="mt-0 text-td-ink-2" style="max-width: var(--td-measure)">Each level has its own rooms, so you only see questions from people learning the same thing. Open a level to see its subjects.</p>
            <div class="grid gap-3">
                @foreach ($curricula as $curriculum)
                    @continue($curriculum->levels->isEmpty())
                    <details @if ($level && $level->curriculum_id === $curriculum->id) open @endif>
                        <summary class="cursor-pointer py-2 font-semibold">{{ $curriculum->name }}</summary>
                        <ul class="m-0 flex list-none flex-wrap gap-2 p-0 pb-3">
                            @foreach ($curriculum->levels as $l)
                                <li><a href="{{ route('rooms.levels.show', $l) }}" class="td-pill td-stage--{{ $l->stage?->code }} no-underline hover:underline" @if ($level?->id === $l->id) aria-current="true" @endif><span class="td-pill__swatch" aria-hidden="true"></span>{{ $l->name }}</a></li>
                            @endforeach
                        </ul>
                    </details>
                @endforeach
            </div>
        </x-td.panel>
    </section>
</x-layouts::learner>
