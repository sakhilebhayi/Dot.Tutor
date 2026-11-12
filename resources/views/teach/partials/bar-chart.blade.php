{{-- One-series column chart drawn as inline SVG from real rows (dataviz rules: one hue on the data, thin columns
     with a 4px rounded end on a single baseline, three faint gridlines, labels in text tokens, the extreme and the
     latest value labelled, a native tooltip on every column and the same numbers as a table underneath).
     $items: list of ['label' => short x label, 'title' => long label, 'value' => int]; $money: values are minor units;
     $currency; $caption: what is plotted; $id: unique id. --}}
@php
    $money = $money ?? false;
    $fmt = function (int $v) use ($money, $currency) {
        if (! $money) {
            return number_format($v, 0, '.', "\u{202F}");
        }
        $major = $v / 100;

        return ($currency === 'ZAR' ? 'R' : $currency.' ').(abs($major) >= 1000 ? number_format($major / 1000, abs($major) >= 10000 ? 0 : 1, '.', '').'k' : number_format($major, 0, '.', "\u{202F}"));
    };
    $fmtFull = fn (int $v) => $money ? strip_tags((string) \App\Domain\Teach\Support\TutorContext::money($v, $currency)) : number_format($v, 0, '.', "\u{202F}");
    $values = array_column($items, 'value');
    $max = max(1, ...array_map(fn ($v) => max(0, $v), $values ?: [0]));
    // A clean top for the axis: 1, 2 or 5 times a power of ten at or above the largest value.
    $pow = 10 ** max(0, (int) floor(log10($max)));
    $top = collect([1, 2, 5, 10])->map(fn ($k) => $k * $pow)->first(fn ($c) => $c >= $max) ?? $max;
    if ($money && $top < 100) { $top = 100; }
    $W = 640; $H = 220; $left = 56; $right = 12; $topPad = 24; $bottom = 28;
    $plotW = $W - $left - $right; $plotH = $H - $topPad - $bottom;
    $n = max(1, count($items));
    $band = $plotW / $n;
    $bw = min(24, $band * 0.6);
    $base = $topPad + $plotH;
    $maxIndex = $values ? array_search(max($values), $values, true) : null;
    $lastIndex = $n - 1;
@endphp
<figure class="m-0 grid gap-2">
    <svg viewBox="0 0 {{ $W }} {{ $H }}" class="block h-auto w-full" role="img" aria-labelledby="{{ $id }}-title">
        <title id="{{ $id }}-title">{{ $caption }}</title>
        @foreach ([0, 0.5, 1] as $f)
            @php $y = $base - $plotH * $f; @endphp
            <line x1="{{ $left }}" x2="{{ $W - $right }}" y1="{{ $y }}" y2="{{ $y }}" class="stroke-td-line" stroke-width="1" />
            <text x="{{ $left - 8 }}" y="{{ $y + 4 }}" text-anchor="end" class="fill-td-ink-2" font-size="12">{{ $fmt((int) round($top * $f)) }}</text>
        @endforeach
        @foreach ($items as $i => $item)
            @php
                $v = max(0, (int) $item['value']);
                $h = $top > 0 ? $plotH * $v / $top : 0;
                $x = $left + $band * $i + ($band - $bw) / 2;
                $y = $base - $h;
                $r = min(4, $h, $bw / 2);
            @endphp
            <g>
                <title>{{ ($item['title'] ?? $item['label']).': '.$fmtFull((int) $item['value']) }}</title>
                {{-- Hit target wider and taller than the mark. --}}
                <rect x="{{ $left + $band * $i }}" y="{{ $topPad }}" width="{{ $band }}" height="{{ $plotH }}" fill="transparent" />
                @if ($h > 0)
                    <path d="M{{ $x }},{{ $base }} V{{ $y + $r }} Q{{ $x }},{{ $y }} {{ $x + $r }},{{ $y }} H{{ $x + $bw - $r }} Q{{ $x + $bw }},{{ $y }} {{ $x + $bw }},{{ $y + $r }} V{{ $base }} Z" class="fill-td-primary" />
                @endif
                @if (($i === $maxIndex || $i === $lastIndex) && $v > 0)
                    <text x="{{ $x + $bw / 2 }}" y="{{ $y - 6 }}" text-anchor="middle" class="fill-td-ink" font-size="12" font-weight="700">{{ $fmt($v) }}</text>
                @endif
                <text x="{{ $left + $band * $i + $band / 2 }}" y="{{ $H - 8 }}" text-anchor="middle" class="fill-td-ink-2" font-size="11">{{ $n > 8 && $i % 2 === 1 ? '' : $item['label'] }}</text>
            </g>
        @endforeach
        <line x1="{{ $left }}" x2="{{ $W - $right }}" y1="{{ $base }}" y2="{{ $base }}" class="stroke-td-edge" stroke-width="1" />
    </svg>
    <details>
        <summary class="cursor-pointer text-sm td-link">Show the numbers</summary>
        <x-td.table :caption="$caption" caption-hidden dense>
            <thead><tr><th scope="col">Period</th><th scope="col" class="is-num">{{ $money ? 'Amount' : 'Count' }}</th></tr></thead>
            <tbody>
                @foreach ($items as $item)
                    <tr><td>{{ $item['title'] ?? $item['label'] }}</td><td class="is-num">{{ $fmtFull((int) $item['value']) }}</td></tr>
                @endforeach
            </tbody>
        </x-td.table>
    </details>
</figure>
