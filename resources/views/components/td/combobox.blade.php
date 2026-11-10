{{-- Searchable choice (resources/js/ds/combobox.js). Submits the chosen value under $name.
     options: list of ['value' => , 'label' => , 'hint' => (optional), 'search' => (optional extra words, e.g. a
     language's own name)]. Without script a native select with the same options is shown instead. --}}
@props(['name', 'id' => null, 'options' => [], 'value' => null, 'placeholder' => 'Type to search', 'emptyText' => 'Nothing matches that yet.', 'hint' => false])
@php
    $id = $id ?? $name;
    $current = (string) old($name, $value);
    $chosen = collect($options)->firstWhere('value', $current);
    $invalid = $errors->has($name);
    $described = trim(($hint ? $id.'-hint ' : '').($invalid ? $id.'-error' : ''));
@endphp
<div class="td-combobox" data-td-combobox>
    <div data-td-combobox-live hidden>
        <input type="text" id="{{ $id }}" class="td-input" role="combobox" aria-autocomplete="list" aria-expanded="false"
            aria-controls="{{ $id }}-listbox" autocomplete="off" placeholder="{{ $placeholder }}" value="{{ $chosen['label'] ?? '' }}"
            @if ($invalid) aria-invalid="true" @endif
            @if ($described !== '') aria-describedby="{{ $described }}" @endif>
        <input type="hidden" name="{{ $name }}" value="{{ $current }}" disabled>
        <ul class="td-combobox__list" role="listbox" id="{{ $id }}-listbox" hidden>
            @foreach ($options as $index => $option)
                <li class="td-combobox__option" role="option" id="{{ $id }}-option-{{ $index }}" data-value="{{ $option['value'] }}" data-label="{{ $option['label'] }}"
                    data-search="{{ \Illuminate\Support\Str::lower($option['label'].' '.($option['search'] ?? '').' '.($option['hint'] ?? '')) }}"
                    aria-selected="{{ $current === (string) $option['value'] ? 'true' : 'false' }}">
                    <span>{{ $option['label'] }}</span>
                    @isset($option['hint'])<small>{{ $option['hint'] }}</small>@endisset
                </li>
            @endforeach
            <li class="td-combobox__empty" data-td-combobox-empty hidden>{{ $emptyText }}</li>
        </ul>
    </div>
    <select data-td-combobox-fallback name="{{ $name }}" id="{{ $id }}-fallback" class="td-select" aria-label="{{ $attributes->get('aria-label', $placeholder) }}">
        <option value="">{{ $placeholder }}</option>
        @foreach ($options as $option)
            <option value="{{ $option['value'] }}" @selected($current === (string) $option['value'])>{{ $option['label'] }}</option>
        @endforeach
    </select>
</div>
