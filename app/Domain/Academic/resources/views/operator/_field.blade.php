@php($id = $prefix.'-'.$name)
@php($value = $model ? $model->getAttribute($name) : null)
@php($value = $value instanceof \BackedEnum ? $value->value : $value)
<div class="t-row">
    @if($field['type'] === 'bool')
        <input type="hidden" name="{{ $name }}" value="0" form="{{ $form }}">
        <label for="{{ $id }}"><input type="checkbox" id="{{ $id }}" name="{{ $name }}" value="1" form="{{ $form }}" @checked($model ? (bool) $value : false)> {{ $field['label'] }}</label>
    @else
        <label class="t-label" for="{{ $id }}">{{ $field['label'] }}</label>
        @if($field['type'] === 'select')
            <select class="t-field" id="{{ $id }}" name="{{ $name }}" form="{{ $form }}">
                @isset($field['nullable'])<option value="">{{ $field['nullable'] }}</option>@endisset
                @foreach($options[$name] as $optionValue => $optionLabel)
                    <option value="{{ $optionValue }}" @selected((string) $value === (string) $optionValue || (! $model && (string) request($name) === (string) $optionValue))>{{ $optionLabel }}</option>
                @endforeach
            </select>
        @elseif($field['type'] === 'textarea')
            <textarea class="t-field" id="{{ $id }}" name="{{ $name }}" rows="2" form="{{ $form }}">{{ $value }}</textarea>
        @elseif($field['type'] === 'list')
            <input class="t-field" id="{{ $id }}" name="{{ $name }}" value="{{ is_array($value) ? implode(', ', $value) : '' }}" form="{{ $form }}">
        @else
            <input class="t-field" id="{{ $id }}" name="{{ $name }}" type="{{ $field['type'] === 'number' ? 'number' : 'text' }}" value="{{ $value }}" form="{{ $form }}">
        @endif
    @endif
</div>
