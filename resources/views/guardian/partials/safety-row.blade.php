{{-- One report, safeguarding case or account notice about a child. $row: a GuardianSafety row. --}}
@php
    $at = $row['at']?->copy()->timezone(config('tutor.timezone'));
    $who = str_contains($row['where'].$row['what'], $row['child']) ? '' : $row['child'].' · ';
@endphp
<li id="{{ $row['key'] }}" @class(['gd-row', 'gd-row--urgent' => $row['urgent'] && $row['open']])>
    <div>
        <p class="gd-row__what">{{ $row['what'] }}</p>
        <p class="gd-row__meta">{{ $who }}{{ $row['where'] }}@if ($at) · <time datetime="{{ $at->toIso8601String() }}">{{ $at->format('j M Y, H:i') }}</time>@endif</p>
    </div>
    <span class="gd-row__status">@include('ops.partials.tag', ['text' => $row['status'], 'tone' => $row['tone']])</span>
    <p class="gd-row__next"><strong>What happens next:</strong> {{ $row['next'] }}</p>
    @if ($row['actions'] !== [])
        <div class="gd-row__actions">
            @foreach ($row['actions'] as $action)
                <x-td.button :href="$action['href']" size="sm">{{ $action['label'] }}</x-td.button>
            @endforeach
        </div>
    @endif
</li>
