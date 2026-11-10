@props(['for'])

@error($for)
    <p {{ $attributes->merge(['class' => 'td-error']) }}>{{ $message }}</p>
@enderror
