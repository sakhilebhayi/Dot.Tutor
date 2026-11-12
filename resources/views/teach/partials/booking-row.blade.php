{{-- One booking as the tutor sees it, with the actions its state allows. The server checks every action again.
     $s: TutorSession, $tz: the tutor's time zone, $compact: hide the price and actions line. --}}
@php
    $status = \App\Enums\SessionStatus::from($s->status);
    $start = $s->starts_at->copy()->timezone($tz);
    $price = $s->price_minor ?? (int) round(((int) $s->rate_minor) * $s->duration_minutes / 60);
    $minutesToStart = now()->diffInMinutes($s->starts_at, false);
    $canStart = $status === \App\Enums\SessionStatus::Confirmed && $minutesToStart <= 15 && $minutesToStart > -$s->duration_minutes;
    $canNoShow = in_array($status, [\App\Enums\SessionStatus::Confirmed, \App\Enums\SessionStatus::InProgress], true) && $minutesToStart <= -15;
    $rowId = 'booking-'.$s->id;
    $who = $s->isGroup() ? 'Group lesson' : \App\Domain\Teach\Support\TutorContext::learnerName($s->student);
@endphp
<li class="grid gap-2 border-t border-td-line py-3 first:border-t-0" id="{{ $rowId }}">
    <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <p class="m-0 font-bold">
            @if (! $s->isGroup() && $s->student_id)
                <a class="td-link" href="{{ route('teach.students.show', $s->student_id) }}">{{ $who }}</a>
            @else
                {{ $who }}
            @endif
            <span class="font-normal text-td-ink-2">, {{ $s->subject?->name ?? 'Subject removed' }}</span>
        </p>
        <p class="m-0 tabular-nums">
            <time datetime="{{ $s->starts_at->toIso8601String() }}">{{ $start->format('D j M, H:i') }}</time>
            <span class="text-td-ink-2">, {{ $s->duration_minutes }} min</span>
        </p>
    </div>
    <p class="m-0 text-sm text-td-ink-2">
        {{ $s->offering?->title ?? 'Lesson' }}
        @if ($s->isGroup()) · {{ (int) $s->seats_taken }} of {{ (int) $s->capacity }} seats taken @endif
        · <x-td.money :minor="$price" :currency="$s->currency ?: 'ZAR'" />{{ $s->isGroup() ? ' a seat' : '' }}
        @if ($s->tutor_package_purchase_id) · paid from a package @endif
        · {{ $status->label() }}
        @if ($status === \App\Enums\SessionStatus::AwaitingPayment && $s->hold_expires_at) (held until {{ $s->hold_expires_at->copy()->timezone($tz)->format('D j M, H:i') }}) @endif
    </p>
    @if ($s->decision_reason && in_array($status, [\App\Enums\SessionStatus::Cancelled], true))
        <p class="m-0 text-sm">Reason: {{ $s->decision_reason }}</p>
    @endif
    @if (! ($compact ?? false))
        <div class="flex flex-wrap items-start gap-2">
            @if ($status === \App\Enums\SessionStatus::Pending)
                <form method="POST" action="{{ route('teach.bookings.act', [$s, 'accept']) }}">@csrf<x-td.button type="submit" variant="primary" size="sm" icon="check">Accept<span class="td-sr"> {{ $who }} {{ $start->format('j M H:i') }}</span></x-td.button></form>
                <details class="grow">
                    <summary class="td-btn td-btn--sm cursor-pointer">Decline<span class="td-sr"> {{ $who }} {{ $start->format('j M H:i') }}</span></summary>
                    <form method="POST" action="{{ route('teach.bookings.act', [$s, 'decline']) }}" class="mt-2 grid max-w-xl gap-2">
                        @csrf
                        <x-td.field label="Reason the learner will see" for="{{ $rowId }}-decline">
                            <x-td.textarea name="reason" id="{{ $rowId }}-decline" rows="2" required />
                        </x-td.field>
                        <div><x-td.button type="submit" variant="danger" size="sm">Decline request</x-td.button></div>
                    </form>
                </details>
            @endif
            @if ($canStart)
                <form method="POST" action="{{ route('teach.bookings.act', [$s, 'start']) }}">@csrf<x-td.button type="submit" variant="primary" size="sm" icon="arrow-right">Start lesson</x-td.button></form>
            @endif
            @if ($status === \App\Enums\SessionStatus::InProgress)
                <form method="POST" action="{{ route('teach.bookings.act', [$s, 'complete']) }}">@csrf<x-td.button type="submit" variant="primary" size="sm" icon="check">Mark complete</x-td.button></form>
            @endif
            @if ($canNoShow)
                <form method="POST" action="{{ route('teach.bookings.act', [$s, 'no-show']) }}">@csrf<x-td.button type="submit" size="sm">Learner did not come</x-td.button></form>
            @endif
            @if (in_array($status, [\App\Enums\SessionStatus::AwaitingPayment, \App\Enums\SessionStatus::Confirmed], true) && $minutesToStart > 0)
                <details>
                    <summary class="td-btn td-btn--sm td-btn--quiet cursor-pointer">Cancel or move<span class="td-sr"> {{ $who }} {{ $start->format('j M H:i') }}</span></summary>
                    <form method="POST" action="{{ route('teach.bookings.act', [$s, 'cancel']) }}" class="mt-2 grid max-w-xl gap-2">
                        @csrf
                        <p class="m-0 text-sm text-td-ink-2">Dot.Tutor has no reschedule step: to move a lesson, cancel it with a reason that suggests a new time, and the learner books again. When you cancel, any payment is refunded in full.</p>
                        <x-td.field label="Reason the learner will see" for="{{ $rowId }}-cancel">
                            <x-td.textarea name="reason" id="{{ $rowId }}-cancel" rows="2" required />
                        </x-td.field>
                        <div><x-td.button type="submit" variant="danger" size="sm">Cancel lesson</x-td.button></div>
                    </form>
                </details>
            @endif
        </div>
    @endif
</li>
