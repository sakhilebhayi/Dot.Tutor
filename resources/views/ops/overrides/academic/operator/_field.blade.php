{{-- Ops restyle of the academic agent's generic field partial (same names, ids and form association). --}}
@php
    $id = $prefix.'-'.$name;
    $value = $model ? $model->getAttribute($name) : null;
    $value = $value instanceof \BackedEnum ? $value->value : $value;
@endphp
@if ($field['type'] === 'bool')
    <div class="td-field">
        <input type="hidden" name="{{ $name }}" value="0" form="{{ $form }}">
        <label class="td-check" for="{{ $id }}"><input type="checkbox" id="{{ $id }}" name="{{ $name }}" value="1" form="{{ $form }}" @checked($model ? (bool) $value : false)><span>{{ $field['label'] }}</span></label>
    </div>
@else
    <div class="td-field">
        <label class="td-label" for="{{ $id }}">{{ $field['label'] }}</label>
        @if ($field['type'] === 'select')
            <span class="td-select-wrap">
                <select class="td-select" id="{{ $id }}" name="{{ $name }}" form="{{ $form }}">
                    @isset($field['nullable'])<option value="">{{ $field['nullable'] }}</option>@endisset
                    @foreach ($options[$name] as $optionValue => $optionLabel)
                        <option value="{{ $optionValue }}" @selected((string) $value === (string) $optionValue || (! $model && (string) request($name) === (string) $optionValue))>{{ $optionLabel }}</option>
                    @endforeach
                </select>
                <x-td.icon name="chevron-down" />
            </span>
        @elseif ($field['type'] === 'textarea')
            <textarea class="td-textarea" id="{{ $id }}" name="{{ $name }}" rows="2" form="{{ $form }}">{{ $value }}</textarea>
        @elseif ($field['type'] === 'list')
            <input class="td-input" id="{{ $id }}" name="{{ $name }}" value="{{ is_array($value) ? implode(', ', $value) : '' }}" form="{{ $form }}">
        @else
            <input class="td-input" id="{{ $id }}" name="{{ $name }}" type="{{ $field['type'] === 'number' ? 'number' : 'text' }}" value="{{ $value }}" form="{{ $form }}">
        @endif
    </div>
@endif
