{{-- Empty, loading, success and error states for any region that loads data.
     kind: empty | loading | success | error. Say what belongs here and the one action that fills it (actions slot). --}}
@props(['kind' => 'empty', 'title', 'icon' => null])
@php
    $glyph = $icon ?? ['empty' => 'inbox', 'loading' => 'loader-circle', 'success' => 'circle-check', 'error' => 'triangle-alert'][$kind] ?? 'info';
    $role = match ($kind) { 'error' => 'alert', 'loading', 'success' => 'status', default => null };
@endphp
<div {{ $attributes->class(['td-state', 'td-state--'.$kind]) }} @if ($role) role="{{ $role }}" @endif @if ($kind === 'loading') aria-busy="true" @endif>
    <span class="td-state__glyph"><x-td.icon :name="$glyph" :spin="$kind === 'loading'" /></span>
    <p class="td-state__title">{{ $title }}</p>
    @if (trim($slot) !== '')
        <div class="td-state__body">{{ $slot }}</div>
    @endif
    @isset($actions)
        <div class="td-page-head__actions">{{ $actions }}</div>
    @endisset
</div>
