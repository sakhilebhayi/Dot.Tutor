@php
    $requestCount = $requests->whereNull('tutor_booking_series_id')->count() + $series->count();
    $finding = match (true) {
        $state['key'] !== 'approved' => $state['label'].'. '.($missing === [] ? 'Your profile has every required step.' : 'Still to do: '.implode(', ', $missing).'.'),
        $requestCount > 0 => $requestCount.' '.\Illuminate\Support\Str::plural('request', $requestCount).' waiting for your answer.',
        $today->isNotEmpty() => $today->count().' '.\Illuminate\Support\Str::plural('lesson', $today->count()).' today.',
        default => 'Nothing waiting on you right now.',
    };
    $money_ = fn ($m) => \App\Domain\Teach\Support\TutorContext::money($m);
@endphp
<x-layouts::workspace title="Today">
    <x-td.page-head title="Today" :lede="$now->format('l j F').'. '.$finding">
        <x-slot:actions>
            @if ($state['key'] !== 'approved')
                <x-td.button href="{{ route('teach.profile') }}" variant="primary" icon="badge-check">Continue your profile</x-td.button>
            @else
                <x-td.button href="{{ route('teach.offerings.create') }}" icon="plus">New offering</x-td.button>
                <x-td.button href="{{ route('teach.calendar') }}" icon="calendar">Calendar</x-td.button>
            @endif
        </x-slot:actions>
    </x-td.page-head>

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
