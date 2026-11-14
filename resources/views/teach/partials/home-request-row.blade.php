{{-- One booking request on the tutor home, answered in the row. Accept posts at once; Decline asks for the reason the
     learner will see (the server requires it). $s: TutorSession, $tz: the tutor's zone. --}}
@php
    $start = $s->starts_at->copy()->timezone($tz);
    $price = $s->price_minor ?? (int) round(((int) $s->rate_minor) * $s->duration_minutes / 60);
    $who = $s->isGroup() ? 'Group lesson' : \App\Domain\Teach\Support\TutorContext::learnerName($s->student);
    $rowId = 'request-'.$s->id;
@endphp
<li class="hm-row hm-row--dense hm-row--form" id="{{ $rowId }}">
    <div>
        <a class="hm-row__link" href="{{ route('sessions.show', $s) }}">{{ $who }}, {{ $s->subject?->name ?? 'Subject removed' }}</a>
        <span class="hm-row__meta"><time datetime="{{ $s->starts_at->toIso8601String() }}">{{ $start->format('D j M, H:i') }}</time> · {{ $s->duration_minutes }} min · <x-td.money :minor="$price" :currency="$s->currency ?: 'ZAR'" />{{ $s->isGroup() ? ' a seat' : '' }}@if ($s->tutor_package_purchase_id) · paid from a package @endif</span>
    </div>
    <div class="hm-row__end">
        <form method="POST" action="{{ route('teach.bookings.act', [$s, 'accept']) }}">@csrf<x-td.button type="submit" variant="secondary" size="sm" icon="check">Accept<span class="td-sr"> {{ $who }} {{ $start->format('j M H:i') }}</span></x-td.button></form>
        <details>
            <summary class="td-btn td-btn--sm td-btn--quiet cursor-pointer">Decline<span class="td-sr"> {{ $who }} {{ $start->format('j M H:i') }}</span></summary>
            <form method="POST" action="{{ route('teach.bookings.act', [$s, 'decline']) }}" class="mt-2 grid max-w-xl gap-2">
                @csrf
                <x-td.field label="Reason the learner will see" for="{{ $rowId }}-decline">
                    <x-td.textarea name="reason" id="{{ $rowId }}-decline" rows="2" required />
                </x-td.field>
                <div><x-td.button type="submit" variant="danger" size="sm">Decline request</x-td.button></div>
            </form>
        </details>
    </div>
</li>
