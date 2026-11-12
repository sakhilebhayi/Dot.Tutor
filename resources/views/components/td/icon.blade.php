{{-- An icon from the self-hosted sprite (public/icons/td-sprite.svg, Lucide, ISC). Decorative by default;
     pass a label when the icon is the only thing saying what a control does. --}}
@props(['name', 'label' => null, 'spin' => false])
<svg {{ $attributes->class(['td-icon', 'td-icon--spin' => $spin]) }} @if ($label) role="img" aria-label="{{ $label }}" @else aria-hidden="true" @endif focusable="false"><use href="{{ asset('icons/td-sprite.svg') }}#i-{{ $name }}"></use></svg>
