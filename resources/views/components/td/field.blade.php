{{-- A labelled field. Wraps any control in its slot, or renders an input when no slot is given.
     The control must carry id="{{ $for }}"; x-td.input, x-td.select and x-td.textarea do this when given the same
     name and id. Hint and error are tied to the control with aria-describedby by those components (describedby). --}}
@props(['label', 'for', 'hint' => null, 'error' => null, 'optional' => false, 'errorBag' => null])
@php
    $errorKey = $errorBag ?? $for;
    $message = $error ?? ($errors->first($errorKey) ?: null);
@endphp
<div {{ $attributes->class(['td-field']) }}>
    <label class="td-label" for="{{ $for }}">
        {{ $label }}
        @if ($optional)<span class="td-label__optional">(optional)</span>@endif
    </label>
    @if ($hint)
        <p class="td-hint" id="{{ $for }}-hint">{{ $hint }}</p>
    @endif
    {{ $slot }}
    @if ($message)
        <p class="td-error" id="{{ $for }}-error"><x-td.icon name="circle-alert" />{{ $message }}</p>
    @endif
</div>
