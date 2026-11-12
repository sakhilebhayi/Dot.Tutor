{{-- The onboarding steps as a timeline: $steps, $step (current key), $profile. --}}
<x-td.timeline label="Steps to become a tutor">
    @foreach ($steps as $s)
        @php
            // Answering "yes" is not being cleared: the under-18s step is ticked only when screening is recorded too (D-2402).
            $screeningWaits = $s['key'] === 'safeguarding' && ($onboarding ?? null)?->teaches_minors && ($screeningMissing ?? []) !== [];
            $itemState = $s['key'] === $step ? 'current' : ($s['done'] && ! $screeningWaits ? 'done' : 'upcoming');
            $itemMeta = $screeningWaits ? 'Answered; screening not recorded yet' : ($s['required'] ? null : 'Optional');
        @endphp
        <x-td.timeline-item :title="$s['title']" :state="$itemState" :meta="$itemMeta">
            @if ($profile || $s['key'] === 'about')
                <a class="td-link" href="{{ route('teach.profile', ['step' => $s['key']]) }}" @if ($s['key'] === $step) aria-current="page" @endif>{{ $s['key'] === $step ? 'You are here' : ($s['done'] ? 'Review' : 'Open') }}<span class="td-sr"> {{ $s['title'] }}</span></a>
            @endif
        </x-td.timeline-item>
    @endforeach
</x-td.timeline>
