@props(['id'])
<div {{ $attributes }} role="tabpanel" id="{{ $id }}" aria-labelledby="{{ $id }}-tab" tabindex="0">{{ $slot }}</div>
