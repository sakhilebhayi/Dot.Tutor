{{-- Native select. options: [value => label] or a list of ['value' => , 'label' => , 'group' => ]. --}}
@props(['name', 'id' => null, 'options' => [], 'value' => null, 'placeholder' => null, 'hint' => false])
@php
    $id = $id ?? $name;
    $invalid = $errors->has($name);
    $selected = (string) old($name, $value);
    $described = trim(($hint ? $id.'-hint ' : '').($invalid ? $id.'-error' : ''));
    $normalised = collect($options)->map(fn ($label, $key) => is_array($label) ? $label : ['value' => $key, 'label' => $label])->values();
    $groups = $normalised->groupBy(fn ($option) => $option['group'] ?? '');
@endphp
<span class="td-select-wrap">
    <select id="{{ $id }}" name="{{ $name }}"
        @if ($invalid) aria-invalid="true" @endif
        @if ($described !== '') aria-describedby="{{ $described }}" @endif
        {{ $attributes->class(['td-select']) }}>
        @if ($placeholder)
            <option value="" @selected($selected === '')>{{ $placeholder }}</option>
        @endif
        @foreach ($groups as $group => $groupOptions)
            @if ($group !== '')<optgroup label="{{ $group }}">@endif
            @foreach ($groupOptions as $option)
                <option value="{{ $option['value'] }}" @selected($selected === (string) $option['value'])>{{ $option['label'] }}</option>
            @endforeach
            @if ($group !== '')</optgroup>@endif
        @endforeach
        {{ $slot }}
    </select>
    <x-td.icon name="chevron-down" />
</span>
