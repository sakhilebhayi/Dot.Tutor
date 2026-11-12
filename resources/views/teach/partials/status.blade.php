{{-- A state in words with its glyph (never colour alone). tone: verified | review | self | rejected | lapsed | peer. --}}
@php
    $glyph = $icon ?? ['verified' => 'circle-check', 'review' => 'hourglass', 'self' => 'circle-dashed', 'rejected' => 'circle-alert', 'lapsed' => 'triangle-alert', 'peer' => 'circle-check'][$tone] ?? 'info';
@endphp
<span class="td-badge td-badge--{{ $tone }}"><x-td.icon :name="$glyph" />{{ $text }}</span>
