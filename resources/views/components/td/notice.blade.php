{{-- An inline message in the page flow. tone: neutral | info | success | warning | danger. --}}
@props(['tone' => 'neutral', 'title' => null, 'icon' => null])
@php
    $glyph = $icon ?? ['info' => 'info', 'success' => 'circle-check', 'warning' => 'triangle-alert', 'danger' => 'circle-alert'][$tone] ?? 'info';
@endphp
<div {{ $attributes->class(['td-notice', 'td-notice--'.$tone => $tone !== 'neutral']) }} @if ($tone === 'danger') role="alert" @endif>
    <x-td.icon :name="$glyph" />
    <div class="td-notice__body">
        @if ($title)<p class="td-notice__title">{{ $title }}</p>@endif
        {{ $slot }}
    </div>
</div>
