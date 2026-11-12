{{-- An icon from the classroom sprite (public/classroom/icons.svg, Lucide, ISC). Decorative: the control names itself. --}}
@props(['name'])
<svg {{ $attributes->class(['td-icon']) }} aria-hidden="true" focusable="false"><use href="{{ asset('classroom/icons.svg') }}#i-{{ $name }}"></use></svg>
