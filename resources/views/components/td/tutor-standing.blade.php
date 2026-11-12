{{-- Where a tutor stands, as three plain facts from the records that decide them (D-2210): listed (profile review),
     qualification (verification level from a reviewed record), under-18s (safeguarding screening). The same component
     and the same words in the tutor workspace and on the operator screens. Colour is never the only signal: each fact
     has its own glyph and words. compact=true drops the one-line explanations. --}}
@props(['profile', 'compact' => false])
@php
    $facts = app(\App\Domain\Integration\Support\TutorStanding::class)->facts($profile);
    $icon = fn ($ok) => match ($ok) { true => 'circle-check', null => 'hourglass', default => 'circle-dashed' };
    $tone = fn ($ok) => match ($ok) { true => 'td-standing__fact--done', null => 'td-standing__fact--waiting', default => 'td-standing__fact--not' };
@endphp
<ul {{ $attributes->class(['td-standing']) }} aria-label="Where this tutor stands">
    @foreach ($facts as $fact)
        <li class="td-standing__fact {{ $tone($fact['ok']) }}" data-standing="{{ $fact['key'] }}">
            <x-td.icon :name="$icon($fact['ok'])" />
            <span>
                <strong>{{ $fact['text'] }}</strong>
                @unless ($compact)<span class="td-standing__detail">{{ $fact['detail'] }}</span>@endunless
            </span>
        </li>
    @endforeach
</ul>
