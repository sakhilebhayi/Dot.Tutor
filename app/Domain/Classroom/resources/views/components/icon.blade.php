{{-- An icon from the classroom sprite (public/icons/classroom.svg, Lucide, ISC). Decorative: the control names itself. --}}
@props(['name'])
<svg {{ $attributes->class(['td-icon']) }} aria-hidden="true" focusable="false"><use href="{{ asset('icons/classroom.svg') }}#i-{{ $name }}"></use></svg>
