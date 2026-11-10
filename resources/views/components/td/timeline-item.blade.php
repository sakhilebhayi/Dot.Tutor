{{-- state: done | current | upcoming. meta: a time or who did it (use a <time> in the slot for exact dates). --}}
@props(['title', 'state' => 'upcoming', 'meta' => null])
<li {{ $attributes->class(['td-timeline__item', 'td-timeline__item--'.$state]) }} @if ($state === 'current') aria-current="step" @endif>
    <span class="td-timeline__mark">
        @if ($state === 'done')<x-td.icon name="check" />@elseif ($state === 'current')<x-td.icon name="circle-dashed" />@endif
    </span>
    <div>
        <p class="td-timeline__title">{{ $title }}<span class="td-sr">, {{ ['done' => 'done', 'current' => 'in progress', 'upcoming' => 'not started'][$state] ?? $state }}</span></p>
        @if ($meta)<p class="td-timeline__meta">{{ $meta }}</p>@endif
        @if (trim($slot) !== '')<div class="td-timeline__body">{{ $slot }}</div>@endif
    </div>
</li>
