{{-- Tutors and courses for this room, ranked by fit to the room's subject, level, curriculum and your languages.
     Never by ratings or popularity. --}}
@php use App\Domain\Community\Support\Names; @endphp
<div class="td-panel__head"><h2 class="td-panel__title">Tutors for this subject and level</h2></div>
<div class="border-b border-td-line px-5 pb-4">
    <p class="mt-0 text-sm text-td-ink-2" style="max-width: var(--td-measure)">
        Ordered by how well each tutor's teaching scope and languages fit this room, not by ratings or popularity.
        @if ($room->isForMinors()) Only tutors with a current safeguarding screening are listed here. @endif
    </p>
    @if ($requestHelpUrl)
        <x-td.button :href="$requestHelpUrl" variant="primary" icon="users">Request tutoring help for {{ $room->subject?->name }}</x-td.button>
        <p class="mb-0 mt-2 text-sm text-td-ink-2">Opens Find a tutor with {{ $room->level?->name }} {{ $room->subject?->name }} already filled in.</p>
    @endif
</div>
@if ($tutors === [])
    <x-td.state kind="empty" title="No approved tutor lists this subject and level yet">
        Request tutoring help to describe what you need; matching looks wider than this room.
    </x-td.state>
@else
    <ul class="m-0 list-none p-0" aria-label="Suggested tutors">
        @foreach ($tutors as $tutor)
            <li class="grid gap-2 border-b border-td-line px-5 py-4 last:border-b-0 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                <div class="flex items-start gap-3">
                    <x-td.avatar :name="$tutor['name']" decorative />
                    <div class="grid gap-1">
                        @if ($tutor['url'])<a class="td-link" href="{{ $tutor['url'] }}">{{ $tutor['name'] }}</a>@else<span class="font-semibold">{{ $tutor['name'] }}</span>@endif
                        @if ($tutor['headline'])<p class="m-0 text-sm text-td-ink-2">{{ $tutor['headline'] }}</p>@endif
                        <x-td.verify-badge :status="$tutor['verification']['level'] ? 'verified' : 'self'" :level="$tutor['verification']['level']" />
                    </div>
                </div>
                <x-td.match-reasons :reasons="$tutor['reasons']" label="Why this tutor is listed" />
            </li>
        @endforeach
    </ul>
@endif

@if ($tutorRooms->isNotEmpty())
    <div class="border-t border-td-line">
        <div class="td-panel__head"><h2 class="td-panel__title">Tutor rooms and workshops</h2></div>
        <ul class="m-0 list-none p-0">
            @foreach ($tutorRooms as $tr)
                <li class="border-b border-td-line px-5 py-3 last:border-b-0">
                    <a class="td-link" href="{{ route('rooms.show', $tr) }}">{{ $tr->title }}</a>
                    <p class="m-0 text-sm text-td-ink-2">{{ Names::short($tr->owner) }} · @if ($tr->isPaid())<x-td.money :minor="$tr->price_minor" :currency="$tr->currency" />@else Free @endif @if ($tr->capacity) · {{ max(0, $tr->capacity - $tr->seats_taken) }} places left @endif</p>
                </li>
            @endforeach
        </ul>
    </div>
@endif

<div class="border-t border-td-line">
    <div class="td-panel__head"><h2 class="td-panel__title">Courses</h2></div>
    @if ($courses->isEmpty())
        <x-td.state kind="empty" title="No published course for this subject and level yet">Courses appear here once a tutor publishes one for {{ $room->level?->name }} {{ $room->subject?->name }}.</x-td.state>
    @else
        <ul class="m-0 list-none p-0">
            @foreach ($courses as $row)
                <li class="grid gap-2 border-b border-td-line px-5 py-4 last:border-b-0 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                    <div>
                        <a class="td-link" href="{{ route('learning.courses.show', $row['course']) }}" lang="{{ $row['course']->original_locale }}">{{ $row['course']->title }}</a>
                        <p class="m-0 text-sm text-td-ink-2">By {{ Names::short($row['course']->owner) }} ·
                            @if (($row['course']->price_minor ?? 0) > 0)<x-td.money :minor="$row['course']->price_minor" :currency="$row['course']->currency" />@else Free @endif</p>
                    </div>
                    <x-td.match-reasons :reasons="$row['reasons']" label="Why this course is listed" />
                </li>
            @endforeach
        </ul>
    @endif
</div>
