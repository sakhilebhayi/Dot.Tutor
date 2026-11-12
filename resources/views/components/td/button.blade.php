{{-- Button or link that looks like one.
     variant: primary | secondary | quiet | danger. size: sm | md | lg. href turns it into a link.
     loading: shows a spinner, sets aria-busy and disables the button (the label stays, so the width holds).
     icon / iconEnd: sprite names. iconOnly: hides the label visually but keeps it for screen readers. --}}
@props([
    'variant' => 'secondary',
    'size' => 'md',
    'href' => null,
    'type' => 'button',
    'icon' => null,
    'iconEnd' => null,
    'iconOnly' => false,
    'loading' => false,
    'block' => false,
    'disabled' => false,
])
@php
    $classes = [
        'td-btn',
        'td-btn--'.$variant => $variant !== 'secondary',
        'td-btn--sm' => $size === 'sm',
        'td-btn--lg' => $size === 'lg',
        'td-btn--block' => $block,
        'td-btn--icon' => $iconOnly,
    ];
@endphp
@if ($href && ! $disabled)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>
        @if ($icon)<x-td.icon :name="$icon" />@endif
        <span @class(['td-sr' => $iconOnly])>{{ $slot }}</span>
        @if ($iconEnd)<x-td.icon :name="$iconEnd" />@endif
    </a>
@elseif ($href)
    <a role="link" aria-disabled="true" {{ $attributes->class($classes) }}>
        @if ($icon)<x-td.icon :name="$icon" />@endif
        <span @class(['td-sr' => $iconOnly])>{{ $slot }}</span>
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->class($classes) }} @disabled($disabled || $loading) @if ($loading) aria-busy="true" @endif>
        @if ($loading)
            <x-td.icon name="loader-circle" spin />
        @elseif ($icon)
            <x-td.icon :name="$icon" />
        @endif
        <span @class(['td-sr' => $iconOnly])>{{ $slot }}</span>
        @if ($iconEnd && ! $loading)<x-td.icon :name="$iconEnd" />@endif
    </button>
@endif
