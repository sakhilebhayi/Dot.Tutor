{{-- A person: their photo when they gave one, otherwise initials on a pale tint that stays the same for them.
     size: sm | md | lg | xl. Decorative when the name is printed beside it (decorative=true). --}}
@props(['name', 'src' => null, 'size' => 'md', 'decorative' => false])
@php
    $words = preg_split('/\s+/', trim($name)) ?: [];
    $initials = mb_strtoupper(mb_substr($words[0] ?? '?', 0, 1).(count($words) > 1 ? mb_substr(end($words), 0, 1) : ''));
    $hue = crc32(mb_strtolower(trim($name))) % 360;
@endphp
<span {{ $attributes->class(['td-avatar', 'td-avatar--'.$size => $size !== 'md']) }} style="--td-avatar-hue: {{ $hue }}"
    @if ($decorative) aria-hidden="true" @else role="img" aria-label="{{ $name }}" @endif>
    @if ($src)
        <img src="{{ $src }}" alt="" loading="lazy" decoding="async">
    @else
        <span aria-hidden="true">{{ $initials }}</span>
    @endif
</span>
