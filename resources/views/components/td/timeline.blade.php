{{-- An ordered history or plan: a booking's life, a verification's steps, a course's path. Fill with x-td.timeline-item. --}}
@props(['label'])
<ol {{ $attributes->class(['td-timeline']) }} aria-label="{{ $label }}">{{ $slot }}</ol>
