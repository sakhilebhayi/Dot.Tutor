@props(['value'])

<label {{ $attributes->merge(['class' => 'td-label block']) }}>
    {{ $value ?? $slot }}
</label>
