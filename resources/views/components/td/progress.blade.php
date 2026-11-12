{{-- Progress meter: a shelf filling up, with the figure always printed. value and max are numbers;
     display overrides the printed figure ("7 of 12 topics"). stage colours the fill with a binding colour. --}}
@props(['label', 'value' => 0, 'max' => 100, 'display' => null, 'stage' => null])
@php
    $ratio = $max > 0 ? max(0, min(1, $value / $max)) : 0;
    $shown = $display ?? round($ratio * 100).'%';
@endphp
<div {{ $attributes->class(['td-meter', 'td-stage--'.$stage => $stage]) }}>
    <div class="td-meter__head">
        <span class="td-meter__label" id="{{ $id = 'meter-'.\Illuminate\Support\Str::random(6) }}">{{ $label }}</span>
        <span class="td-meter__value">{{ $shown }}</span>
    </div>
    <div class="td-meter__track" role="progressbar" aria-labelledby="{{ $id }}" aria-valuemin="0" aria-valuemax="{{ $max }}" aria-valuenow="{{ $value }}" aria-valuetext="{{ $shown }}"
        style="--td-meter: {{ $ratio }};@if ($stage) --td-meter-fill: var(--td-stage-{{ in_array($stage, ['honours', 'pg_diploma', 'masters'], true) ? 'postgraduate' : $stage }});@endif">
        <span class="td-meter__fill"></span>
    </div>
</div>
