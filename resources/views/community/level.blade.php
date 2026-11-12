@php
    use App\Domain\Community\Models\Room;
    $curriculumName = $level->curriculum?->short_name ?: $level->curriculum?->name;
    $stage = $level->stage?->code;
@endphp
<x-layouts::learner :title="$level->name.', '.$curriculumName" :step="$step">
    <nav aria-label="Breadcrumb" class="mb-3 text-sm"><a class="td-link" href="{{ route('rooms.index') }}">My rooms</a> <span class="text-td-ink-2">/ {{ $level->name }}</span></nav>
    <x-td.page-head :title="$level->name.', '.$curriculumName" :lede="'Every subject offered at '.$level->name.' in '.$level->curriculum?->name.' has a room. '.($audience === Room::UNDER_18 ? 'These are the rooms for learners under 18.' : 'These are the rooms for adult learners.')">
        <x-slot:actions>
            @if ($stage)<x-td.pill kind="stage" :stage="$stage">{{ $level->stage?->name }}</x-td.pill>@endif
        </x-slot:actions>
    </x-td.page-head>

    @if ($canSwitchAudience)
        <x-td.tabs label="Audience" class="mb-6" :items="[
            ['label' => 'Adult learners', 'href' => route('rooms.levels.show', $level), 'current' => $audience === Room::ADULT],
            ['label' => 'Learners under 18', 'href' => route('rooms.levels.show', [$level, 'audience' => Room::UNDER_18]), 'current' => $audience === Room::UNDER_18],
        ]" />
        @if ($audience === Room::UNDER_18)
            <x-td.notice tone="info" class="mb-6">Rooms for learners under 18 open to adults only after a safeguarding screening. Each room checks again when you open it.</x-td.notice>
        @endif
    @endif

    <div class="td-panels td-panels--2">
        <x-td.panel title="Subject rooms" flush>
            @if ($rows->isEmpty())
                <x-td.state kind="empty" title="No subjects are listed for this level yet">An academic editor adds the subjects each level offers.</x-td.state>
            @else
                <x-td.table caption="Subject rooms at {{ $level->name }}" caption-hidden>
                    <thead><tr><th>Subject</th><th class="is-num">Waiting for an answer</th><th class="is-num">Resolved</th><th>Last activity</th></tr></thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                <td>
                                    <a class="td-link" href="{{ route('rooms.open', [$level, $row['offering'], 'audience' => $audience]) }}">{{ $row['offering']->subject->name }}</a>
                                    @if ($row['is_profile_subject'])<span class="block text-sm text-td-ink-2">On your profile</span>@endif
                                </td>
                                <td class="is-num">{{ $row['room'] ? $row['open_questions'] : '—' }}</td>
                                <td class="is-num">{{ $row['room'] ? $row['resolved_questions'] : '—' }}</td>
                                <td class="text-td-ink-2">{{ $row['last_activity_at'] ? \Illuminate\Support\Carbon::parse($row['last_activity_at'])->diffForHumans() : 'No posts yet' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-td.table>
            @endif
        </x-td.panel>

        <x-td.panel title="Tutor rooms at this level">
            @forelse ($tutorRooms as $room)
                <div class="border-b border-td-line py-3 first:pt-0 last:border-b-0">
                    <a class="td-link" href="{{ route('rooms.show', $room) }}">{{ $room->title }}</a>
                    <p class="m-0 text-sm text-td-ink-2">{{ $room->subject?->name }} · {{ \App\Domain\Community\Support\Names::short($room->owner) }} ·
                        @if ($room->isPaid())<x-td.money :minor="$room->price_minor" :currency="$room->currency" />@else Free @endif</p>
                </div>
            @empty
                <p class="m-0 text-td-ink-2">No tutor runs a room at this level yet. Tutors start rooms and workshops from their workspace.</p>
            @endforelse
        </x-td.panel>
    </div>
</x-layouts::learner>
