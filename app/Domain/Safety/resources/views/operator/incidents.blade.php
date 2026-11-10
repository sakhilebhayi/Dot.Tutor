<x-app-layout>
<x-safety::frame>
    <p><a href="{{ route('safety.operator.queue') }}">Queue</a></p>
    <h1>Safeguarding incidents</h1>
    <div class="scroll">
    <table>
        <thead><tr><th scope="col">Reference</th><th scope="col">Severity</th><th scope="col">Status</th><th scope="col">Respond by</th></tr></thead>
        <tbody>
        @forelse ($incidents as $incident)
            <tr>
                <td><a href="{{ route('safety.operator.incidents.show', $incident['id']) }}">{{ $incident['reference'] }}</a><div class="muted">{{ $incident['title'] }}</div></td>
                <td><span class="tag tag-{{ $incident['severity'] }}">{{ ucfirst($incident['severity']) }}</span>@if ($incident['escalation_level'] > 0) <span class="muted">level {{ $incident['escalation_level'] }}</span>@endif</td>
                <td><span class="tag tag-{{ $incident['status'] }}">{{ ucfirst($incident['status']) }}</span></td>
                <td>{{ $incident['respond_by'] ? \Illuminate\Support\Carbon::parse($incident['respond_by'])->timezone(config('tutor.timezone'))->format('j M, H:i') : 'Acknowledged' }}@if ($incident['overdue']) <strong>overdue</strong>@endif</td>
            </tr>
        @empty
            <tr><td colspan="4" class="muted">No incidents.</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
</x-safety::frame>
</x-app-layout>
