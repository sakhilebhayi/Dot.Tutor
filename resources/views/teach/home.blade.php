@php
    $requestCount = $requests->whereNull('tutor_booking_series_id')->count() + $series->count();
    $money_ = fn ($m) => \App\Domain\Teach\Support\TutorContext::money($m);
    // The arrival's one primary action (R-50), from the tutor's own records: finish the application, then answer
    // requests, then the next lesson, then set up an offering. No learner is named on the arrival.
    $upcoming = $today->first(fn ($s) => $s->status !== 'completed' && $s->starts_at->copy()->addMinutes((int) $s->duration_minutes)->isFuture()) ?? $week->first();
    $activeOfferings = $profile->offerings()->where('is_active', true)->count();
    [$primary, $slip] = match (true) {
        $state['key'] !== 'approved' && $missing !== [] => [
            ['label' => 'Continue your profile', 'href' => route('teach.profile'), 'icon' => 'badge-check'],
            ['label' => $state['label'], 'text' => 'Still to do: '.implode(', ', $missing).'.'],
        ],
        $state['key'] !== 'approved' => [
            ['label' => 'Open your profile', 'href' => route('teach.profile'), 'icon' => 'badge-check'],
            ['label' => $state['label'], 'text' => 'Your profile has every required step.'],
        ],
        $requestCount > 0 => [
            ['label' => 'Answer booking requests', 'href' => route('teach.bookings')],
            ['label' => 'Waiting for your answer', 'text' => $requestCount.' '.\Illuminate\Support\Str::plural('request', $requestCount).'.'],
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
@endphp
<x-layouts::workspace title="Today">
    <x-td.arrival headline="Run your teaching from one desk"
        lede="Dot.Tutor keeps your profile, offerings, bookings, lessons and payouts together, so learners can find you and you can see what the day needs."
        :primary="$primary" :slip="$slip">
        @if ($state['key'] === 'approved')
            @if ($primary['href'] !== route('teach.offerings.create'))<x-td.button variant="quiet" href="{{ route('teach.offerings.create') }}" icon="plus">New offering</x-td.button>@endif
            @if ($primary['href'] !== route('teach.calendar'))<x-td.button variant="quiet" href="{{ route('teach.calendar') }}" icon="calendar">Calendar</x-td.button>@endif
            @if ($primary['href'] !== route('teach.bookings'))<x-td.button variant="quiet" href="{{ route('teach.bookings') }}" icon="list-checks">Bookings</x-td.button>@endif
        @endif
    </x-td.arrival>

    {{-- The next lesson comes first whenever there is one (R-47 fix D-2408, critic B: on the phone the real lesson sat
         below an empty request panel and two money tiles). Same order at every width, so reading and focus order match. --}}
    <div class="grid gap-6">
        @if ($today->isNotEmpty() || $week->isNotEmpty())
            @include('teach.partials.today-lessons')
            @include('teach.partials.today-requests')
        @else
            @include('teach.partials.today-requests')
            @include('teach.partials.today-lessons')
        @endif
    </div>
</x-layouts::workspace>
