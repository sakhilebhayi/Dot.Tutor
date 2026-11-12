{{-- Bar list for a distribution (dataviz: magnitude, one hue, figure printed in ink, longest first).
     rows: list of ['label', 'value', 'lang' => optional BCP 47 code]; empty: what an empty list means. --}}
@props(['rows', 'label', 'empty' => 'Nothing recorded yet.'])
@php($max = max(1, (int) collect($rows)->max('value')))
@if (collect($rows)->isEmpty())
    <p class="m-0 ops-muted">{{ $empty }}</p>
@else
    <ul class="ops-bars" aria-label="{{ $label }}">
        @foreach ($rows as $row)
            <li>
                <span class="ops-bars__label" @isset($row['lang']) lang="{{ $row['lang'] }}" @endisset>{{ $row['label'] }}</span>
                <span class="ops-bars__track" aria-hidden="true"><span class="ops-bars__fill" style="width: {{ round($row['value'] / $max * 100, 1) }}%"></span></span>
                <span class="ops-bars__value">{{ $row['value'] }}</span>
            </li>
        @endforeach
    </ul>
@endif
