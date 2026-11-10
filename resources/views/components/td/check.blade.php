{{-- Checkbox or radio with its label beside it. --}}
@props(['name', 'id' => null, 'type' => 'checkbox', 'value' => '1', 'checked' => false])
@php $id = $id ?? $name.($type === 'radio' ? '-'.$value : ''); @endphp
<label class="td-check" for="{{ $id }}">
    <input type="{{ $type }}" id="{{ $id }}" name="{{ $name }}" value="{{ $value }}" @checked(old($name) !== null ? (string) old($name) === (string) $value : $checked) {{ $attributes }}>
    <span>{{ $slot }}</span>
</label>
