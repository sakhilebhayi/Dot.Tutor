{{-- /my/bookings (marketplace.bookings): the learner's lessons (D-1110). From the marketplace controller: $asLearner,
     $asTutor (BookingPresenter rows). LessonsComposer adds $seats and $packages. --}}
@php
    use App\Domain\Student\Support\Times;
    $show = request('show') === 'past' ? 'past' : 'upcoming';
    $open = ['awaiting_guardian', 'requested', 'awaiting_payment', 'confirmed', 'in_progress'];
    $upcoming = $asLearner->filter(fn ($b) => in_array($b['state'], $open, true))->sortBy('starts_at')->values();
    $past = $asLearner->reject(fn ($b) => in_array($b['state'], $open, true))->values();
    $rows = $show === 'past' ? $past : $upcoming;
    $openSeats = $seats->filter(fn ($s) => in_array($s->status, ['awaiting_guardian', 'awaiting_payment', 'confirmed'], true) && $s->session?->starts_at?->isFuture());
    $stateToStatus = ['requested' => 'pending'];
@endphp
<x-student.shell title="Lessons">
    <x-td.page-head title="Lessons" lede="Every lesson you asked for, what happens next, and what it costs.">
        <x-slot:actions><x-td.button variant="primary" icon="search" :href="route('marketplace.find')">Find a tutor</x-td.button></x-slot:actions>
    </x-td.page-head>

    @if ($errors->any())
        <x-td.notice tone="danger" title="That did not go through">{{ $errors->first() }}</x-td.notice>
    @endif

    <x-td.tabs label="Which lessons" :items="[
        ['label' => 'Coming up', 'href' => route('marketplace.bookings'), 'current' => $show === 'upcoming', 'count' => $upcoming->count() + $openSeats->count()],
        ['label' => 'Past and cancelled', 'href' => route('marketplace.bookings', ['show' => 'past']), 'current' => $show === 'past', 'count' => $past->count()],
    ]" />

    <x-td.panel :title="$show === 'past' ? 'Past and cancelled' : 'Coming up'" flush>
        @if ($rows->isEmpty() && ($show === 'past' || $openSeats->isEmpty()))
            <x-td.state kind="empty" :title="$show === 'past' ? 'Nothing here yet' : 'No lessons booked'">
                {{-- The page head already carries "Find a tutor" (R-47 polish D-2018: one button per action). --}}
                @if ($show === 'upcoming') Book one lesson at a time with "Find a tutor" above. There is no subscription. @endif
            </x-td.state>
        @else
            <ul class="st-list">
                @foreach ($rows as $b)
                    <li class="st-row">
                        <div>
                            <p class="st-row__title"><a href="{{ route('learn.lessons.show', $b['id']) }}">{{ $b['subject']['name'] ?? 'Lesson' }} with {{ $b['tutor']['name'] }}</a></p>
                            <p class="st-row__meta"><time datetime="{{ $b['starts_at'] }}">{{ Times::when($b['starts_at']) }}</time> · {{ $b['duration_minutes'] }} min · {{ $b['price']['display'] }}</p>
                            <p class="st-row__meta">{{ $b['next_step'] }}</p>
                        </div>
                        <div class="st-row__end">
                            @include('student.partials.lesson-state', ['status' => $stateToStatus[$b['state']] ?? $b['state']])
                            <x-td.button size="sm" :href="route('learn.lessons.show', $b['id'])">Open</x-td.button>
                        </div>
                    </li>
                @endforeach
                @if ($show === 'upcoming')
                    @foreach ($openSeats as $seat)
                        <li class="st-row">
                            <div>
                                <p class="st-row__title"><a href="{{ route('learn.lessons.show', $seat->tutor_session_id) }}">{{ $seat->session->subject?->name ?? 'Group lesson' }}, group with {{ $seat->session->tutorProfile->displayName() }}</a></p>
                                <p class="st-row__meta"><time datetime="{{ $seat->session->starts_at->toIso8601String() }}">{{ Times::when($seat->session->starts_at) }}</time> · your seat · <x-td.money :minor="(int) $seat->price_minor" :currency="$seat->currency" /></p>
                            </div>
                            <div class="st-row__end">@include('student.partials.lesson-state', ['status' => $seat->status])<x-td.button size="sm" :href="route('learn.lessons.show', $seat->tutor_session_id)">Open</x-td.button></div>
                        </li>
                    @endforeach
                @endif
            </ul>
            <p class="st-small" style="padding:0 var(--td-row-pad-x) var(--td-space-3)">{{ Times::zoneNote() }}</p>
        @endif
    </x-td.panel>

    @if ($packages->isNotEmpty())
        <x-td.panel title="Lesson packages" flush>
            <ul class="st-list">
                @foreach ($packages as $p)
                    <li class="st-row">
                        <div>
                            <p class="st-row__title">{{ $p['lessons_total'] }} lessons with {{ $p['tutor_name'] }}</p>
                            <p class="st-row__meta">{{ $p['lessons_left'] }} left · {{ $p['price']['display'] }}@if ($p['expires_at']) · use by {{ Times::when($p['expires_at']) }}@endif</p>
                        </div>
                        <div class="st-row__end">
                            @include('student.partials.lesson-state', ['status' => $p['state'] === 'active' ? 'confirmed' : $p['state']])
                            @if ($p['state'] === 'active' && $p['lessons_left'] > 0)<x-td.button size="sm" :href="route('learn.book', $p['offering_id'])">Book a lesson</x-td.button>@endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </x-td.panel>
    @endif

    @if ($asTutor->isNotEmpty())
        <x-td.panel title="Requests to you as a tutor" flush>
            <x-slot:actions>@if (Route::has('teach.bookings'))<x-td.button size="sm" variant="quiet" :href="route('teach.bookings')">Open your tutor workspace</x-td.button>@endif</x-slot:actions>
            <ul class="st-list">
                @foreach ($asTutor->take(10) as $b)
                    <li class="st-row">
                        <div>
                            <p class="st-row__title">{{ $b['subject']['name'] ?? 'Lesson' }} · {{ Times::when($b['starts_at']) }}</p>
                            <p class="st-row__meta">{{ $b['state_label'] }} · {{ $b['next_step'] }}</p>
                        </div>
                        <div class="st-row__end">
                            @if ($b['state'] === 'requested')
                                <form method="POST" action="{{ route('marketplace.bookings.act', [$b['id'], 'accept']) }}">@csrf<x-td.button type="submit" size="sm" variant="primary">Accept</x-td.button></form>
                                <details>
                                    <summary class="td-btn td-btn--sm">Decline</summary>
                                    <form method="POST" action="{{ route('marketplace.bookings.act', [$b['id'], 'decline']) }}" class="st-form" style="margin-top:var(--td-space-2)">@csrf
                                        <x-td.field label="Reason for declining" for="reason-{{ $b['id'] }}"><x-td.input name="reason" id="reason-{{ $b['id'] }}" required minlength="3" maxlength="1000" /></x-td.field>
                                        <x-td.button type="submit" size="sm">Decline</x-td.button>
                                    </form>
                                </details>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </x-td.panel>
    @endif
</x-student.shell>
