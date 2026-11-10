{{-- Level or subject pill. kind=stage wears the stage's binding colour (stage: foundation, intermediate, senior,
     fet, undergraduate, honours, pg_diploma, masters, doctoral, professional). kind=level shows a small swatch of
     its stage beside the level name. kind=subject is an outlined neutral pill. --}}
@props(['kind' => 'subject', 'stage' => null])
@php
    $classes = match ($kind) {
        'stage' => ['td-pill', 'td-pill--stage', 'td-stage--'.$stage],
        'level' => ['td-pill', 'td-stage--'.$stage],
        default => ['td-pill', 'td-pill--subject'],
    };
@endphp
<span {{ $attributes->class($classes) }}>
    @if ($kind === 'level' && $stage)<span class="td-pill__swatch" aria-hidden="true"></span>@endif
    {{ $slot }}
</span>
