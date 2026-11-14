{{-- The shelf: original artwork for the head and for calm empty states. Book spines in the product's own greens, drawn
     here, not a picture of anything that exists on the page. Decorative (aria-hidden). variant: band | empty. --}}
@props(['variant' => 'empty'])
@php
    // [x, width, height]: a fixed row, so the same shelf draws every time.
    $spines = $variant === 'band'
        ? [[2, 9, 8], [12, 7, 11], [20, 10, 7], [31, 8, 12], [40, 7, 9], [48, 11, 13], [60, 8, 8], [69, 9, 11], [79, 7, 12], [87, 10, 9], [98, 8, 13], [107, 9, 8], [117, 7, 11], [125, 10, 9]]
        : [[2, 12, 28], [15, 9, 36], [25, 13, 30], [39, 10, 40], [50, 12, 26], [63, 9, 34], [73, 14, 38], [88, 10, 29], [99, 12, 35], [112, 9, 31], [122, 11, 25]];
    $h = $variant === 'band' ? 14 : 44;
    $w = $variant === 'band' ? 136 : 136;
    $inked = $variant === 'band' ? 5 : 6;
@endphp
<svg {{ $attributes->class(['hm-shelf', 'hm-shelf--band' => $variant === 'band']) }} viewBox="0 0 {{ $w }} {{ $h }}" width="{{ $w }}" height="{{ $h }}" aria-hidden="true" focusable="false">
    @foreach ($spines as $i => [$x, $sw, $sh])
        <rect @class(['hm-shelf__spine', 'hm-shelf__spine--ink' => $i === $inked]) x="{{ $x }}" y="{{ $h - 1 - $sh }}" width="{{ $sw }}" height="{{ $sh }}" rx="1" />
    @endforeach
    <line class="hm-shelf__base" x1="0" x2="{{ $w }}" y1="{{ $h - 0.5 }}" y2="{{ $h - 0.5 }}" />
</svg>
