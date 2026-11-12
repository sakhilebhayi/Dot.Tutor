{{-- One urgent safety matter about a child, as the first thing on the page (D-2101). $row: a GuardianSafety row. --}}
@php
    $at = $row['at']?->copy()->timezone(config('tutor.timezone'));
    $who = str_contains($row['where'].$row['what'], $row['child']) ? '' : $row['child'].' · ';
@endphp
<section class="gd-urgent" aria-labelledby="urgent-{{ $row['key'] }}">
    <div class="gd-urgent__head">
        <x-td.icon name="circle-alert" />
        <div class="grid gap-1">
            <h2 class="gd-urgent__title" id="urgent-{{ $row['key'] }}">{{ $row['what'] }}</h2>
            <p class="gd-urgent__meta">{{ $who }}{{ $row['where'] }}@if ($at) · <time datetime="{{ $at->toIso8601String() }}">{{ $at->format('j M Y, H:i') }}</time>@endif</p>
        </div>
    </div>
    <p><strong>{{ $row['status'] }}.</strong> {{ $row['next'] }}</p>
    <div class="gd-urgent__actions">
        @unless (request()->routeIs('safety.guardian.dashboard'))
            <x-td.button :href="route('safety.guardian.dashboard').'#'.$row['key']" icon-end="arrow-right">See the report</x-td.button>
        @endunless
        @foreach ($row['actions'] as $action)
            <x-td.button :href="$action['href']">{{ $action['label'] }}</x-td.button>
        @endforeach
    </div>
    <p class="gd-urgent__help">If {{ $row['child'] }} is in danger now, call the police on <a href="tel:10111">10111</a> or Childline on <a href="tel:116">116</a> (free, day and night).</p>
</section>
