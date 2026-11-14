{{-- One-series column chart (dataviz: one hue on the data, the current period in the full hue and earlier periods the
     neutral edge step; zero baseline; recessive grid; the current value printed in ink; each bar focusable with a
     plain-text tooltip; a table view beside it so nothing depends on hover or colour). No legend: one series, named by
     the section heading above it.
     points: list of ['label', 'value', 'current' => bool]; caption: what is counted; unit: noun for the hover text. --}}
@props(['points', 'caption', 'unit' => 'items'])
@php
    $w = 720; $h = 200; $left = 36; $bottom = 26; $top = 18;
    $max = max(1, (int) collect($points)->max('value'));
    // Round, integer ticks with the top above the largest value (D-2211).
    $scale = \App\Support\ChartScale::nice($max);
    $ceiling = $scale['top'];
    $n = max(1, count($points));
    $slot = ($w - $left) / $n;
    $bar = max(4, min(40, $slot * 0.62));
    $y = fn ($v) => $top + ($h - $top - $bottom) * (1 - $v / $ceiling);
@endphp
<figure class="hm-chart m-0" data-chart>
    <svg viewBox="0 0 {{ $w }} {{ $h }}" role="img" aria-label="{{ $caption }}" preserveAspectRatio="xMidYMid meet">
        @foreach ($scale['ticks'] as $g)
            <line class="hm-chart__grid" x1="{{ $left }}" x2="{{ $w }}" y1="{{ $y($g) }}" y2="{{ $y($g) }}" />
            <text class="hm-chart__tick" x="{{ $left - 8 }}" y="{{ $y($g) + 4 }}" text-anchor="end">{{ $g }}</text>
        @endforeach
        @foreach ($points as $i => $p)
            @php($x = $left + $slot * $i + ($slot - $bar) / 2)
            <g>
                @if ($p['value'] > 0)
                    <path @class(['hm-chart__bar', 'hm-chart__bar--now' => $p['current'] ?? false])
                        d="M{{ $x }},{{ $y(0) }} V{{ $y($p['value']) + 4 }} q0,-4 4,-4 h{{ $bar - 8 }} q4,0 4,4 V{{ $y(0) }} Z" />
                @endif
                @if (($p['current'] ?? false) || $p['value'] === $max)
                    <text class="hm-chart__value" x="{{ $x + $bar / 2 }}" y="{{ $y($p['value']) - 6 }}" text-anchor="middle">{{ $p['value'] }}</text>
                @endif
                @if ($i % 2 === ($n - 1) % 2)
                    <text class="hm-chart__label" x="{{ $x + $bar / 2 }}" y="{{ $h - 8 }}" text-anchor="middle">{{ $p['label'] }}</text>
                @endif
                <rect class="hm-chart__hit" x="{{ $left + $slot * $i }}" y="{{ $top }}" width="{{ $slot }}" height="{{ $h - $top - $bottom }}"
                    tabindex="0" data-tip="{{ $p['value'] }} {{ $unit }}, week of {{ $p['label'] }}{{ ($p['current'] ?? false) ? ' (this week, so far)' : '' }}"
                    aria-label="{{ $p['value'] }} {{ $unit }}, week of {{ $p['label'] }}{{ ($p['current'] ?? false) ? ' (this week, so far)' : '' }}" />
            </g>
        @endforeach
    </svg>
    <details class="hm-table text-sm">
        <summary>Show as a table</summary>
        <x-td.table :caption="$caption" caption-hidden dense>
            <thead><tr><th scope="col">Week starting</th><th scope="col" class="is-num">{{ ucfirst($unit) }}</th></tr></thead>
            <tbody>
                @foreach ($points as $p)
                    <tr><td>{{ $p['label'] }}{{ ($p['current'] ?? false) ? ' (this week, so far)' : '' }}</td><td class="is-num">{{ $p['value'] }}</td></tr>
                @endforeach
            </tbody>
        </x-td.table>
    </details>
</figure>
