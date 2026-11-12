{{-- One-series column chart (dataviz: one hue on the data, the current period in the full hue, earlier periods a
     lighter step; recessive grid; values in ink; native hover titles; a table view beside it).
     points: list of ['label', 'value', 'current' => bool]; caption: what is counted; unit: noun for the hover text. --}}
@props(['points', 'caption', 'unit' => 'items'])
@php
    $w = 720; $h = 200; $left = 36; $bottom = 26; $top = 18;
    $max = max(1, (int) collect($points)->max('value'));
    $step = $max <= 4 ? 1 : (int) ceil($max / 4);
    $ceiling = $step * (int) ceil($max / $step);
    $n = max(1, count($points));
    $slot = ($w - $left) / $n;
    $bar = max(4, min(40, $slot * 0.62));
    $y = fn ($v) => $top + ($h - $top - $bottom) * (1 - $v / $ceiling);
@endphp
<figure class="m-0 grid gap-3">
    <svg class="ops-columns" viewBox="0 0 {{ $w }} {{ $h }}" role="img" aria-label="{{ $caption }}" preserveAspectRatio="xMidYMid meet">
        @for ($g = 0; $g <= $ceiling; $g += $step)
            <line class="ops-columns__grid" x1="{{ $left }}" x2="{{ $w }}" y1="{{ $y($g) }}" y2="{{ $y($g) }}" />
            <text class="ops-columns__axis" x="{{ $left - 8 }}" y="{{ $y($g) + 4 }}" text-anchor="end">{{ $g }}</text>
        @endfor
        @foreach ($points as $i => $p)
            @php($x = $left + $slot * $i + ($slot - $bar) / 2)
            <g>
                <title>{{ $p['label'] }}: {{ $p['value'] }} {{ $unit }}</title>
                <rect class="ops-columns__hit" x="{{ $left + $slot * $i }}" y="{{ $top }}" width="{{ $slot }}" height="{{ $h - $top - $bottom }}" />
                @if ($p['value'] > 0)
                    <path @class(['ops-columns__bar', 'ops-columns__bar--current' => $p['current'] ?? false])
                        d="M{{ $x }},{{ $y(0) }} V{{ $y($p['value']) + 4 }} q0,-4 4,-4 h{{ $bar - 8 }} q4,0 4,4 V{{ $y(0) }} Z" />
                @endif
                @if (($p['current'] ?? false) || $p['value'] === $max)
                    <text class="ops-columns__value" x="{{ $x + $bar / 2 }}" y="{{ $y($p['value']) - 6 }}" text-anchor="middle">{{ $p['value'] }}</text>
                @endif
                @if ($i % 2 === ($n - 1) % 2)
                    <text class="ops-columns__axis" x="{{ $x + $bar / 2 }}" y="{{ $h - 8 }}" text-anchor="middle">{{ $p['label'] }}</text>
                @endif
            </g>
        @endforeach
    </svg>
    <details class="text-sm">
        <summary class="cursor-pointer">Show as a table</summary>
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
