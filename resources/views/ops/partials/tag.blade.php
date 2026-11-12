{{-- A status in words with a glyph. tone: neutral | info | success | warning | danger. --}}
@php
    $tone = $tone ?? 'neutral';
    $glyph = $icon ?? ['danger' => 'circle-alert', 'warning' => 'triangle-alert', 'success' => 'circle-check', 'info' => 'info'][$tone] ?? 'circle-dashed';
@endphp
<span @class(['ops-tag', 'ops-tag--'.$tone => $tone !== 'neutral'])><x-td.icon :name="$glyph" />{{ $text }}</span>
