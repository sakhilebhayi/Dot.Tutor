@php
    use App\Domain\Community\Models\Post;
    use App\Domain\Community\Models\Room;
    use App\Domain\Community\Models\RoomMember;
    use App\Domain\Community\Support\Names;
    $curriculumName = $room->curriculum?->short_name ?: $room->curriculum?->name;
    $heading = $room->isTutorRoom() ? $room->title : $room->subject?->name;
    $tabUrl = fn (string $t, array $extra = []) => route('rooms.show', array_merge([$room, 'tab' => $t], $extra));
    $languageNames = $languages->map(fn ($l) => $l->name_native ?: $l->name_en);
@endphp
<x-layouts::learner :title="$room->displayName()" :step="$step">
    <nav aria-label="Breadcrumb" class="mb-3 text-sm">
        <a class="td-link" href="{{ route('rooms.index') }}">My rooms</a>
        <span class="text-td-ink-2">/</span>
        <a class="td-link" href="{{ route('rooms.levels.show', $room->isForMinors() ? [$room->level, 'audience' => Room::UNDER_18] : [$room->level]) }}">{{ $room->level?->name }}, {{ $curriculumName }}</a>
        <span class="text-td-ink-2">/ {{ $heading }}</span>
    </nav>

    <x-td.page-head :title="$heading"
        :lede="$room->isTutorRoom()
            ? 'A tutor room for '.$room->subject?->name.', '.$room->level?->name.' ('.$curriculumName.'), run by '.Names::short($room->owner).'.'
            : 'Questions, explanations and study material for '.$room->level?->name.' '.$room->subject?->name.' ('.$curriculumName.').'">
        <x-slot:actions>
            @if ($canParticipate)
                <x-td.button :href="route('rooms.ask', $room)" variant="primary" icon="circle-help">Ask a question</x-td.button>
            @endif
            @if ($requestHelpUrl)
                <x-td.button :href="$requestHelpUrl" variant="secondary" icon="users">Request tutoring help</x-td.button>
            @endif
        </x-slot:actions>
    </x-td.page-head>

    <div class="mb-6">@include('community.partials.scope', ['room' => $room])</div>

    @include('community.partials.poll', ['room' => $room, 'version' => $version])

    <x-td.tabs label="Room sections" class="mb-6 max-sm:flex-wrap" :items="[
        ['label' => 'Questions', 'href' => $tabUrl('questions'), 'current' => $tab === 'questions', 'count' => $counts['open'] ?: null],
        ['label' => 'Discussions', 'href' => $tabUrl('discussions'), 'current' => $tab === 'discussions'],
        ['label' => 'Material', 'href' => $tabUrl('material'), 'current' => $tab === 'material'],
        ['label' => 'Tutors and courses', 'href' => $tabUrl('tutors'), 'current' => $tab === 'tutors'],
        ['label' => $room->isTutorRoom() ? 'Workshops and groups' : 'Study groups', 'href' => $tabUrl('groups'), 'current' => $tab === 'groups'],
    ]" />

    <div class="td-panels td-panels--2">
        <div class="td-panel">
            @if ($tab === 'questions')
                <div class="td-panel__head">
                    <h2 class="td-panel__title">Questions</h2>
                    <nav aria-label="Filter questions" class="flex flex-wrap gap-4 text-sm">
                        @foreach (['all' => 'All', 'open' => 'Waiting for an answer', 'resolved' => 'Resolved'] as $key => $label)
                            <a class="td-link" href="{{ $tabUrl('questions', ['state' => $key, 'lang' => $lang]) }}" @if ($state === $key) aria-current="true" style="text-decoration-thickness:2px" @endif>{{ $label }}</a>
                        @endforeach
                    </nav>
                </div>
                @include('community.partials.thread-list', ['empty' => $state === 'resolved' ? 'No resolved questions yet' : 'No questions here yet', 'emptyBody' => 'Asking is how rooms start. Someone at your level has probably wondered the same thing.', 'emptyAction' => ['label' => 'Ask the first question', 'href' => route('rooms.ask', $room)]])
            @elseif ($tab === 'discussions')
                <div class="td-panel__head">
                    <h2 class="td-panel__title">Discussions, explanations and revision techniques</h2>
                    <nav aria-label="Filter discussions" class="flex flex-wrap gap-4 text-sm">
                        <a class="td-link" href="{{ $tabUrl('discussions') }}" @if (! $kind) aria-current="true" @endif>All</a>
                        @foreach (Post::DISCUSSION_KINDS as $k)
                            <a class="td-link" href="{{ $tabUrl('discussions', ['kind' => $k]) }}" @if ($kind === $k) aria-current="true" @endif>{{ Post::kindLabel($k) }}s</a>
                        @endforeach
                    </nav>
                </div>
                @if ($canParticipate)
                    <div class="flex flex-wrap gap-2 border-b border-td-line px-5 py-3">
                        <x-td.button :href="route('rooms.ask', [$room, 'kind' => 'discussion'])" variant="secondary" size="sm" icon="message-square">Start a discussion</x-td.button>
                        <x-td.button :href="route('rooms.ask', [$room, 'kind' => 'explanation'])" variant="secondary" size="sm" icon="notebook-pen">Share an explanation</x-td.button>
                        <x-td.button :href="route('rooms.ask', [$room, 'kind' => 'technique'])" variant="secondary" size="sm" icon="list-checks">Share a revision technique</x-td.button>
                    </div>
                @endif
                @include('community.partials.thread-list', ['empty' => 'Nothing here yet', 'emptyBody' => 'Explain something you understand well, or share how you revise. It helps the next person more than you think.', 'emptyAction' => null])
            @elseif ($tab === 'material')
                <div class="td-panel__head">
                    <h2 class="td-panel__title">Shared in this room</h2>
                    @if ($canParticipate)
                        <x-td.button :href="route('rooms.ask', [$room, 'kind' => 'material'])" variant="secondary" size="sm" icon="paperclip">Share library material</x-td.button>
                    @endif
                </div>
                @include('community.partials.thread-list', ['empty' => 'No material shared here yet', 'emptyBody' => 'Material lives in the library, with its licence. Upload it there, then share it here.', 'emptyAction' => Route::has('learning.library.index') ? ['label' => 'Open the library', 'href' => route('learning.library.index', ['subject_id' => $room->subject_id, 'level_id' => $room->academic_level_id])] : null])

                <div class="border-t border-td-line">
                    <div class="td-panel__head"><h2 class="td-panel__title">From the library for this subject and level</h2></div>
                    @if ($library->isEmpty())
                        <x-td.state kind="empty" title="The library has nothing for this subject and level yet">Notes, summaries and past papers uploaded to the library appear here.</x-td.state>
                    @else
                        <ul class="m-0 list-none p-0">
                            @foreach ($library as $resource)
                                <li class="border-b border-td-line px-5 py-3 last:border-b-0">
                                    <a class="td-link" href="{{ route('learning.library.show', $resource->id) }}" lang="{{ $resource->language }}">{{ $resource->title }}</a>
                                    <p class="m-0 text-sm text-td-ink-2">{{ ucfirst(str_replace('_', ' ', $resource->type?->value ?? '')) }} · {{ $languageNames[$resource->language] ?? strtoupper((string) $resource->language) }} · {{ ucfirst(str_replace('_', ' ', $resource->quality_tier?->value ?? 'community')) }}</p>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @elseif ($tab === 'tutors')
                @include('community.partials.tutors-tab')
            @else
                @include('community.partials.groups-tab')
            @endif
        </div>

        <aside class="td-panel" aria-labelledby="room-side-title">
            <div class="td-panel__head"><h2 class="td-panel__title" id="room-side-title">This room</h2></div>
            <div class="td-panel__body grid gap-5">
                <x-td.stat-strip label="Room questions" :items="[
                    ['label' => 'Waiting for an answer', 'value' => $counts['open']],
                    ['label' => 'Resolved', 'value' => $counts['resolved']],
                ]" />

                <div class="grid gap-2">
                    <p class="td-label m-0">Languages used here</p>
                    @if ($languageCodes === [] && ! $room->language)
                        <p class="m-0 text-sm text-td-ink-2">No posts yet. Write in the language you think in; others can ask for a translation.</p>
                    @else
                        <div class="flex flex-wrap gap-2">
                            @if ($room->language && $languages->has($room->language))
                                @php($l = $languages[$room->language])
                                <x-td.language-chip :code="$l->code" :name="$l->name_en" :endonym="$l->name_native" role="teaches" />
                            @endif
                            @foreach ($languageCodes as $code)
                                @php($l = $languages[$code] ?? null)
                                <a href="{{ $lang === $code ? $tabUrl($tab) : $tabUrl($tab, ['lang' => $code]) }}" class="no-underline" @if ($lang === $code) aria-current="true" @endif>
                                    <x-td.language-chip :code="$code" :name="$l?->name_en ?? strtoupper($code)" :endonym="$l?->name_native" :match="$lang === $code" /><span class="td-sr">{{ $lang === $code ? ': showing only these posts, choose to show every language' : ': show only posts in this language' }}</span>
                                </a>
                            @endforeach
                        </div>
                        @if ($lang)<p class="m-0 text-sm"><a class="td-link" href="{{ $tabUrl($tab) }}">Show every language</a></p>@endif
                    @endif
                </div>

                <div class="grid gap-2">
                    @if ($member?->isActive() && $member->role !== RoomMember::OWNER)
                        <p class="m-0 text-sm text-td-ink-2">You are {{ $member->role === RoomMember::TUTOR ? 'a tutor' : 'a member' }} of this room.</p>
                        <form method="POST" action="{{ route('rooms.leave', $room) }}">@csrf<x-td.button type="submit" variant="quiet" size="sm" icon="log-out">Leave this room</x-td.button></form>
                    @elseif (! $member?->isActive() && ! $room->isTutorRoom() && $canParticipate)
                        <form method="POST" action="{{ route('rooms.join', $room) }}">@csrf<x-td.button type="submit" variant="secondary" icon="plus">Add to my rooms</x-td.button></form>
                    @elseif ($member?->role === RoomMember::OWNER)
                        <p class="m-0 text-sm text-td-ink-2">You run this room. {{ $room->capacity ? $room->seats_taken.' of '.$room->capacity.' places taken.' : $room->seats_taken.' members.' }}</p>
                    @endif
                </div>

                @if ($room->isForMinors())
                    <p class="m-0 text-sm text-td-ink-2">This room is for learners under 18. Contact details are hidden in posts, and posts from learners under 13 are checked by a moderator first. Use Report on anything that worries you.</p>
                @else
                    <p class="m-0 text-sm text-td-ink-2">Use Report on anything that breaks the rules: a moderator reviews every report.</p>
                @endif
            </div>
        </aside>
    </div>
</x-layouts::learner>
