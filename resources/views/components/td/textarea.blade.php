@props(['name', 'id' => null, 'value' => null, 'hint' => false, 'rows' => 5])
@php
    $id = $id ?? $name;
    $invalid = $errors->has($name);
    $described = trim(($hint ? $id.'-hint ' : '').($invalid ? $id.'-error' : ''));
@endphp
<textarea id="{{ $id }}" name="{{ $name }}" rows="{{ $rows }}"
    @if ($invalid) aria-invalid="true" @endif
    @if ($described !== '') aria-describedby="{{ $described }}" @endif
    {{ $attributes->class(['td-textarea']) }}>{{ old($name, $value ?? $slot) }}</textarea>
