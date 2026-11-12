{{-- The onboarding steps as a timeline: $steps, $step (current key), $profile. --}}
<x-td.timeline label="Steps to become a tutor">
    @foreach ($steps as $s)
        @php $itemState = $s['key'] === $step ? 'current' : ($s['done'] ? 'done' : 'upcoming'); @endphp
        <x-td.timeline-item :title="$s['title']" :state="$itemState" :meta="$s['required'] ? null : 'Optional'">
            @if ($profile || $s['key'] === 'about')
                <a class="td-link" href="{{ route('teach.profile', ['step' => $s['key']]) }}" @if ($s['key'] === $step) aria-current="page" @endif>{{ $s['key'] === $step ? 'You are here' : ($s['done'] ? 'Review' : 'Open') }}<span class="td-sr"> {{ $s['title'] }}</span></a>
            @endif
        </x-td.timeline-item>
    @endforeach
</x-td.timeline>
