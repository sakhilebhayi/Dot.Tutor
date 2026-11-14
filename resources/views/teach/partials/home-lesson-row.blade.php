{{-- One booked lesson on the tutor home: time, who, and the action its state allows on the day. The server checks every
     action again (teach.bookings.act). $s: TutorSession, $tz: the tutor's zone. --}}
@php
    $status = \App\Enums\SessionStatus::from($s->status);
    $start = $s->starts_at->copy()->timezone($tz);
    $isToday = $start->isSameDay(now($tz));
    $price = $s->price_minor ?? (int) round(((int) $s->rate_minor) * $s->duration_minutes / 60);
    $minutesToStart = now()->diffInMinutes($s->starts_at, false);
    $canStart = $status === \App\Enums\SessionStatus::Confirmed && $minutesToStart <= 15 && $minutesToStart > -$s->duration_minutes;
    $canNoShow = in_array($status, [\App\Enums\SessionStatus::Confirmed, \App\Enums\SessionStatus::InProgress], true) && $minutesToStart <= -15;
    $who = $s->isGroup() ? 'Group lesson' : \App\Domain\Teach\Support\TutorContext::learnerName($s->student);
    [$tagText, $tagIcon, $tagTone] = match ($status) {
        \App\Enums\SessionStatus::Confirmed => ['Confirmed', 'circle-check', 'ok'],
        \App\Enums\SessionStatus::InProgress => ['Happening now', 'circle-dashed', 'ok'],
        \App\Enums\SessionStatus::AwaitingPayment => ['Awaiting payment', 'clock', 'warn'],
        \App\Enums\SessionStatus::Completed => ['Done', 'check', 'neutral'],
        default => [$status->label(), 'info', 'neutral'],
    };
@endphp
<li class="hm-row hm-row--dense hm-row--time" id="home-booking-{{ $s->id }}">
    <span class="hm-time"><time datetime="{{ $s->starts_at->toIso8601String() }}">{{ $isToday ? 'Today '.$start->format('H:i') : $start->format('D j M H:i') }}</time></span>
    <div>
        <a class="hm-row__link" href="{{ route('sessions.show', $s) }}">{{ $who }}, {{ $s->subject?->name ?? 'Subject removed' }}</a>
        <span class="hm-row__meta">{{ $s->duration_minutes }} min · <x-td.money :minor="$price" :currency="$s->currency ?: 'ZAR'" />@if ($s->isGroup()) a seat, {{ (int) $s->seats_taken }} of {{ (int) $s->capacity }} taken @endif
            @if ($status === \App\Enums\SessionStatus::AwaitingPayment && $s->hold_expires_at) · held until {{ $s->hold_expires_at->copy()->timezone($tz)->format('D j M, H:i') }}@endif</span>
    </div>
    <div class="hm-row__end">
        <x-td.home-tag :text="$tagText" :icon="$tagIcon" :tone="$tagTone" />
        @if (in_array($status, [\App\Enums\SessionStatus::Confirmed, \App\Enums\SessionStatus::InProgress], true) && \Illuminate\Support\Facades\Route::has('classroom.show'))
            <x-td.button href="{{ route('classroom.show', $s) }}" size="sm" icon="video">Classroom<span class="td-sr"> for {{ $who }} {{ $start->format('j M H:i') }}</span></x-td.button>
        @endif
        @if ($canStart)
            <form method="POST" action="{{ route('teach.bookings.act', [$s, 'start']) }}">@csrf<x-td.button type="submit" variant="secondary" size="sm" icon="arrow-right">Start lesson</x-td.button></form>
        @endif
        @if ($status === \App\Enums\SessionStatus::InProgress)
            <form method="POST" action="{{ route('teach.bookings.act', [$s, 'complete']) }}">@csrf<x-td.button type="submit" size="sm" icon="check">Mark complete</x-td.button></form>
        @endif
        @if ($canNoShow)
            <form method="POST" action="{{ route('teach.bookings.act', [$s, 'no-show']) }}">@csrf<x-td.button type="submit" size="sm" variant="quiet">Learner did not come</x-td.button></form>
        @endif
    </div>
</li>
