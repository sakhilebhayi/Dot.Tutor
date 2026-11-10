{{-- Text-like input. Pass id and name; hint/error ids follow the x-td.field convention ({id}-hint, {id}-error). --}}
@props(['name', 'id' => null, 'type' => 'text', 'value' => null, 'hint' => false])
@php
    $id = $id ?? $name;
    $key = str_replace(['[', ']'], ['.', ''], $name);
    $invalid = $errors->has($key);
    $described = trim(($hint ? $id.'-hint ' : '').($invalid ? $id.'-error' : ''));
@endphp
<input type="{{ $type }}" id="{{ $id }}" name="{{ $name }}"
    @if ($type !== 'password' && $type !== 'file') value="{{ old($key, $value) }}" @endif
    @if ($invalid) aria-invalid="true" @endif
    @if ($described !== '') aria-describedby="{{ $described }}" @endif
    {{ $attributes->class(['td-input']) }}>
