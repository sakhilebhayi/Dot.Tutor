{{-- File picker: a real file input enlarged into a drop target. maxKb is shown and checked before upload
     (the server validates again). accept limits the types the dialog offers. --}}
@props(['name', 'id' => null, 'label' => 'Choose a file', 'hint' => null, 'accept' => null, 'multiple' => false, 'maxKb' => null])
@php
    $id = $id ?? str_replace(['[', ']'], '', $name);
    $key = str_replace(['[]', '[', ']'], ['', '.', ''], $name);
    $invalid = $errors->has($key) || $errors->has($key.'.*');
    $message = $errors->first($key) ?: $errors->first($key.'.*');
@endphp
<div {{ $attributes->class(['td-field']) }}>
    <div class="td-file" data-td-file @if ($maxKb) data-td-max-bytes="{{ $maxKb * 1024 }}" @endif>
        <label class="td-file__title" for="{{ $id }}"><x-td.icon name="upload" />{{ $label }}</label>
        <p class="td-hint" id="{{ $id }}-hint">
            {{ $hint ?? 'Drop it here or choose from your device.' }}
            @if ($maxKb) Up to {{ $maxKb >= 1024 ? round($maxKb / 1024, 1).' MB' : $maxKb.' KB' }} each. @endif
        </p>
        <input type="file" id="{{ $id }}" name="{{ $name }}" @if ($accept) accept="{{ $accept }}" @endif @if ($multiple) multiple @endif
            aria-describedby="{{ $id }}-hint{{ $invalid ? ' '.$id.'-error' : '' }}" @if ($invalid) aria-invalid="true" @endif>
        <ul class="td-file__list" data-td-file-list aria-live="polite"></ul>
    </div>
    @if ($invalid)
        <p class="td-error" id="{{ $id }}-error"><x-td.icon name="circle-alert" />{{ $message }}</p>
    @endif
</div>
