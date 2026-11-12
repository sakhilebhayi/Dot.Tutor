{{-- Approvals that are no longer waiting, so a decision leaves a trace (D-2102). $decisions from GuardianView. --}}
@php
    $kinds = ['booking' => 'A lesson booking', 'purchase' => 'A purchase', 'paid_room' => 'A paid study room', 'live_lesson' => 'A live one-to-one lesson'];
    $tz = config('tutor.timezone');
@endphp
<ul class="ops-work">
    @foreach ($decisions as $d)
        @php
            [$text, $tone] = match ($d['status']) {
                'approved' => [$d['by_you'] ? 'You approved' : 'Another guardian approved', 'success'],
                'declined' => [$d['by_you'] ? 'You declined' : 'Another guardian declined', 'neutral'],
                'expired' => ['Nobody decided in time', 'neutral'],
                'cancelled' => ['Request withdrawn', 'neutral'],
                default => [ucfirst($d['status']), 'neutral'],
            };
        @endphp
        <li>
            <div class="grid gap-1">
                <p class="ops-work__title">{{ $d['summary'] ?: ($kinds[$d['kind']] ?? 'A request') }}</p>
                <p class="ops-work__meta">{{ $kinds[$d['kind']] ?? ucfirst(str_replace('_', ' ', $d['kind'])) }} for {{ $d['child'] }}@if ($d['amount_minor'] !== null) · {{ \App\Domain\Money\Money::of((int) $d['amount_minor'], $d['currency'] ?: null)->format() }}@endif · <time datetime="{{ $d['at']->toIso8601String() }}">{{ $d['at']->copy()->timezone($tz)->format('j M Y, H:i') }}</time></p>
                @if ($d['note'])
                    <p class="ops-work__meta">{{ $d['by_you'] ? 'Your reason' : 'Their reason' }}: “{{ $d['note'] }}”</p>
                @endif
            </div>
            @include('ops.partials.tag', ['text' => $text, 'tone' => $tone, 'icon' => match ($d['status']) { 'approved' => 'circle-check', 'declined' => 'x', default => 'hourglass' }])
        </li>
    @endforeach
</ul>
