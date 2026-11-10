<x-app-layout>
<x-safety::frame>
    <h1>Moderation queue</h1>
    <p class="lede">Open reports, child-safety first, then by response deadline. {{ $counts['critical'] }} critical, {{ $counts['high'] }} high, {{ $counts['normal'] }} normal, {{ $counts['low'] }} low.</p>
    <p><a href="{{ route('safety.operator.incidents.index') }}">Incidents</a></p>

    <div class="scroll">
    <table>
        <thead><tr><th scope="col">Priority</th><th scope="col">Report</th><th scope="col">About</th><th scope="col">Respond by</th></tr></thead>
        <tbody>
        @forelse ($reports as $report)
            <tr>
                <td><span class="tag tag-{{ $report['priority'] }}">{{ ucfirst($report['priority']) }}</span>@if ($report['involves_minor']) <span class="tag">Under 18</span>@endif</td>
                <td><a href="{{ route('safety.operator.reports.show', $report['id']) }}">#{{ $report['id'] }} {{ $report['category_label'] }}</a>@if ($report['incident']) <span class="muted">{{ $report['incident']['reference'] }}</span>@endif</td>
                <td>{{ $report['reported_user']['name'] ?? class_basename($report['type']) }}</td>
                <td>{{ \Illuminate\Support\Carbon::parse($report['respond_by'])->timezone(config('tutor.timezone'))->format('j M, H:i') }}@if ($report['overdue']) <strong>overdue</strong>@endif</td>
            </tr>
        @empty
            <tr><td colspan="4" class="muted">No open reports.</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
</x-safety::frame>
</x-app-layout>
