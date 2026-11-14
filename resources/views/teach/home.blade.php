@php
    use Illuminate\Support\Str;
    $requestCount = $requests->whereNull('tutor_booking_series_id')->count() + $series->count();
    $single = $requests->whereNull('tutor_booking_series_id');
    $money_ = fn ($m) => \App\Domain\Teach\Support\TutorContext::money($m);
    // The head's one primary action (R-50), from the tutor's own records: finish the application, then answer requests,
    // then the next lesson, then set up an offering. The headline says the same in a true sentence (R-51). No learner is
    // named in the head.
    $upcoming = $today->first(fn ($s) => $s->status !== 'completed' && $s->starts_at->copy()->addMinutes((int) $s->duration_minutes)->isFuture()) ?? $week->first();
    $activeOfferings = $profile->offerings()->where('is_active', true)->count();
    $approved = $state['key'] === 'approved';
    [$primary, $slip] = match (true) {
        ! $approved && $missing !== [] => [
            ['label' => 'Continue your profile', 'href' => route('teach.profile'), 'icon' => 'badge-check'],
            ['label' => $state['label'], 'text' => 'Still to do: '.implode(', ', $missing).'.'],
        ],
        ! $approved => [
            ['label' => 'Open your profile', 'href' => route('teach.profile'), 'icon' => 'badge-check'],
            ['label' => $state['label'], 'text' => 'Your profile has every required step.'],
        ],
        $requestCount > 0 => [
            ['label' => 'Answer booking requests', 'href' => route('teach.bookings')],
            ['label' => 'Waiting for your answer', 'text' => $requestCount.' '.Str::plural('request', $requestCount).'.'],
        ],
        $upcoming !== null => [
            ['label' => 'Open your calendar', 'href' => route('teach.calendar'), 'icon' => 'calendar'],
            ['label' => 'Your next lesson', 'text' => ($upcoming->subject?->name ?? 'Lesson').', '.$upcoming->starts_at->copy()->timezone($tz)->format('D j M, H:i').'.'],
        ],
        $activeOfferings === 0 => [
            ['label' => 'Add your first offering', 'href' => route('teach.offerings.create'), 'icon' => 'plus'],
            ['label' => 'No offering yet', 'text' => 'Learners can book you once you list what you teach and its price.'],
        ],
        default => [
            ['label' => 'Open your calendar', 'href' => route('teach.calendar'), 'icon' => 'calendar'],
            ['label' => 'Nothing booked this week', 'text' => 'No requests are waiting and no lessons are booked in the next 7 days.'],
        ],
    };
    $profileHeadline = match ($state['key']) {
        'review' => 'Your profile is under review.',
        'rejected' => 'Your profile was not approved.',
        'suspended' => 'Your profile is suspended.',
        default => 'Your profile is not sent yet.',
    };
    [$headline, $sub] = match (true) {
        ! $approved => [$profileHeadline, $slip['text']],
        $requestCount > 0 => [$requestCount.' booking '.Str::plural('request', $requestCount).' '.($requestCount === 1 ? 'is' : 'are').' waiting for your answer.', 'Accept or decline in the list below. The learner is told either way.'],
        $upcoming !== null => ['Your next lesson is '.($upcoming->subject?->name ?? 'a lesson').', '.$upcoming->starts_at->copy()->timezone($tz)->format('D j M, H:i').'.', 'No requests are waiting for an answer.'],
        $activeOfferings === 0 => ['You have no offering yet.', $slip['text']],
        default => ['Nothing is booked this week.', $slip['text']],
    };
    $attentionItems = array_slice($attention, 0, 6);
    $lessonCount = $today->where('status', '!=', 'completed')->count() + $week->count();
@endphp
<x-layouts::workspace title="Today">
    <div class="hm">
    <x-td.home-head :headline="$headline" :sub="$sub" :primary="$primary">
        @if ($approved)
            @if ($primary['href'] !== route('teach.offerings.create'))<x-td.button variant="quiet" href="{{ route('teach.offerings.create') }}" icon="plus">New offering</x-td.button>@endif
            @if ($primary['href'] !== route('teach.calendar'))<x-td.button variant="quiet" href="{{ route('teach.calendar') }}" icon="calendar">Calendar</x-td.button>@endif
            @if ($primary['href'] !== route('teach.bookings'))<x-td.button variant="quiet" href="{{ route('teach.bookings') }}" icon="list-checks">Bookings</x-td.button>@endif
        @endif
    </x-td.home-head>

    @if (! $approved)
        <div class="hm-one">
            <section aria-labelledby="todo-title">
                <div class="hm-sech"><h2 id="todo-title">Still to do</h2>@if ($missing !== [])<span class="hm-sech__n">{{ count($missing) }} required</span>@endif<x-td.button variant="quiet" size="sm" :href="route('teach.profile')">Your profile</x-td.button></div>
                <ul class="hm-rows">
                    @forelse ($missing as $title)
                        <li class="hm-row hm-row--dense"><a class="hm-row__link" href="{{ route('teach.profile') }}">{{ $title }}</a></li>
                    @empty
                        <li class="hm-row hm-row--dense hm-row--empty"><span>Your profile has every required step. Send it for review from your profile.</span></li>
                    @endforelse
                </ul>
                <p class="hm-note">Learners find you only once an operator approves your profile.</p>
            </section>
        </div>
    @else
        <div class="hm-split">
            <section aria-labelledby="answer-title">
                <div class="hm-sech">
                    <h2 id="answer-title">Needs your answer</h2>
                    <span class="hm-sech__n">{{ $requestCount }} {{ Str::plural('request', $requestCount) }}, {{ count($attention) }} to look at</span>
                    <x-td.button variant="quiet" size="sm" :href="route('teach.bookings')">All bookings</x-td.button>
                </div>
                <ul class="hm-rows">
                    @foreach ($series as $sr)
                        @php $firstOfSeries = $sr->sessions->first(); @endphp
                        <li class="hm-row hm-row--dense hm-row--form">
                            <div>
                                <span class="hm-row__title">Weekly lessons</span>
                                <span class="hm-row__meta">{{ $sr->sessions->where('status', 'pending')->count() }} weeks from {{ $firstOfSeries?->starts_at->copy()->timezone($tz)->format('D j M, H:i') }}</span>
                            </div>
                            <div class="hm-row__end"><form method="POST" action="{{ route('teach.bookings.series.accept', $sr) }}">@csrf<x-td.button type="submit" size="sm" icon="check">Accept the series</x-td.button></form></div>
                        </li>
                    @endforeach
                    @foreach ($single->take(6) as $s)
                        @include('teach.partials.home-request-row', ['s' => $s, 'tz' => $tz])
                    @endforeach
                    @foreach ($attentionItems as $item)
                        <li class="hm-row hm-row--dense">
                            <div>
                                <a class="hm-row__link" href="{{ route('sessions.show', $item['session']) }}">{{ $item['session']->isGroup() ? 'Group lesson' : \App\Domain\Teach\Support\TutorContext::learnerName($item['session']->student) }}, {{ $item['session']->starts_at->copy()->timezone($tz)->format('j M H:i') }}</a>
                                <span class="hm-row__meta">{{ $item['reason'] }}</span>
                            </div>
                            <div class="hm-row__end"><x-td.home-tag text="Needs attention" icon="triangle-alert" tone="warn" /></div>
                        </li>
                    @endforeach
                    @if ($requestCount === 0 && $attentionItems === [])
                        <li class="hm-row hm-row--dense hm-row--empty"><span>No requests waiting and nothing overdue, disputed or about to lapse. <a class="td-link" href="{{ route('teach.storefront') }}">Share your storefront</a>.</span></li>
                    @endif
                </ul>
                @if ($single->count() > 6)<p class="hm-note"><a class="td-link" href="{{ route('teach.bookings') }}">See all {{ $requests->count() }} requests</a></p>@endif

                <div class="hm-sech hm-gap"><h2>Money</h2><x-td.button variant="quiet" size="sm" :href="route('teach.earnings')">Earnings</x-td.button></div>
                <dl class="hm-facts">
                    <dt>Available</dt><dd class="hm-big">{{ $money_($money['available']) }} <small>can be paid out</small></dd>
                    <dt>Pending</dt><dd>{{ $money_($money['pending']) }} <small>{{ $money['next_release'] ? 'next frees up '.$money['next_release']['at']->format('j M') : ($money['awaiting_delivery'] > 0 ? 'waiting for lessons to be delivered' : 'nothing pending') }}</small></dd>
                    <dt>Next payout run</dt><dd>{{ $money['next_run']->format('D j M, H:i') }}</dd>
                    <dt>Payouts</dt>
                    @if ($money['blocker'])
                        <dd class="hm-plain">{{ $money['blocker'] }} @unless ($money['has_account'])<a class="td-link" href="{{ route('teach.payouts') }}#bank">Add bank details</a>@endunless</dd>
                    @else
                        <dd class="hm-plain">Available earnings are drafted into a payout then; the bank transfer follows when finance pays it.</dd>
                    @endif
                </dl>
            </section>

            <section aria-labelledby="lessons-title">
                <div class="hm-sech"><h2 id="lessons-title">Lessons, today and next 7 days</h2><x-td.button variant="quiet" size="sm" :href="route('teach.calendar')">Calendar</x-td.button></div>
                <ul class="hm-rows">
                    @forelse ($today as $s)
                        @include('teach.partials.home-lesson-row', ['s' => $s, 'tz' => $tz])
                    @empty
                        <li class="hm-row hm-row--dense hm-row--empty"><span>No lessons today.</span></li>
                    @endforelse
                    @foreach ($week->take(6) as $s)
                        @include('teach.partials.home-lesson-row', ['s' => $s, 'tz' => $tz])
                    @endforeach
                </ul>
                @if ($week->isEmpty())<p class="hm-note">Nothing else this week. Booked lessons show here with the actions you need on the day.</p>@endif
                @if ($week->count() > 6)<p class="hm-note"><a class="td-link" href="{{ route('teach.calendar') }}">{{ $week->count() - 6 }} more in the calendar</a></p>@endif

                <div class="hm-sech hm-gap"><h2>Also</h2></div>
                <ul class="hm-rows">
                    <li class="hm-row hm-row--dense">
                        <div><span class="hm-row__title">Waiting on a guardian</span><span class="hm-row__meta">{{ $guardianHolds === 0 ? 'No requests from under-18s are waiting for a parent or guardian.' : $guardianHolds.' '.Str::plural('request', $guardianHolds).' from a learner under 18 '.($guardianHolds === 1 ? 'is' : 'are').' waiting for their guardian. Nothing is needed from you now.' }}</span></div>
                        <div class="hm-row__end">@if ($guardianHolds > 0)<x-td.home-tag :text="(string) $guardianHolds" icon="hourglass" tone="info" />@endif</div>
                    </li>
                    <li class="hm-row hm-row--dense">
                        <div><a class="hm-row__link" href="{{ $messagesUrl }}">Messages</a><span class="hm-row__meta">{{ $unread > 0 ? $unread.' unread '.Str::plural('message', $unread).'.' : 'No unread messages'.($conversationCount === 0 ? ' yet' : '').'.' }}</span></div>
                        <div class="hm-row__end">@if ($unread > 0)<x-td.home-tag :text="(string) $unread" icon="message-square" tone="info" />@endif</div>
                    </li>
                    @if ($assistantUrl)
                        <li class="hm-row hm-row--dense"><div><a class="hm-row__link" href="{{ $assistantUrl }}">Teaching assistant</a><span class="hm-row__meta">Planning a lesson? Ask for help.</span></div></li>
                    @endif
                </ul>
            </section>
        </div>
    @endif

    <p class="hm-foot">
        <span><b>{{ $lessonCount }}</b> {{ Str::plural('lesson', $lessonCount) }} in the next 7 days</span>
        <span><b>{{ $requestCount }}</b> {{ Str::plural('request', $requestCount) }}</span>
        <span><b>{{ $unread }}</b> unread {{ Str::plural('message', $unread) }}</span>
        <span>Times in your time zone: {{ $tz }}</span>
    </p>
    </div>
</x-layouts::workspace>
