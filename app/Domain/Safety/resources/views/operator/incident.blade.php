<x-app-layout>
<x-safety::frame>
    <p><a href="{{ route('safety.operator.incidents.index') }}">Incidents</a></p>
    <h1>{{ $incident['reference'] }}: {{ $incident['title'] }}</h1>
    <div class="panel">
        <div class="row"><span>Severity</span><span class="tag tag-{{ $incident['severity'] }}">{{ ucfirst($incident['severity']) }}</span></div>
        <div class="row"><span>Status</span><span class="tag tag-{{ $incident['status'] }}">{{ ucfirst($incident['status']) }}</span></div>
        <div class="row"><span>Escalation level</span><span>{{ $incident['escalation_level'] }}</span></div>
        @if ($incident['external_reference'])<div class="row"><span>Outside reference</span><span>{{ $incident['external_reference'] }}</span></div>@endif
    </div>

    <h2>Timeline</h2>
    @foreach ($incident['events'] as $event)
        <div class="row"><span><strong>{{ str_replace('_', ' ', $event['type']) }}</strong> {{ $event['body'] }}</span><span class="muted">{{ \Illuminate\Support\Carbon::parse($event['at'])->timezone(config('tutor.timezone'))->format('j M, H:i') }}</span></div>
    @endforeach

    @if ($incident['status'] !== 'closed')
        <form method="POST" action="{{ route('safety.operator.incidents.update', $incident['id']) }}" class="panel">
            @csrf
            <label for="incident-action">Next step</label>
            <select id="incident-action" name="action">
                <option value="acknowledge">Acknowledge (take ownership, stops escalation)</option>
                <option value="note">Add a note</option>
                <option value="refer">Record a referral outside (SAPS, DSD, FPB hotline)</option>
                <option value="close">Close with an outcome</option>
            </select>
            <label for="incident-body">Note, referral details or outcome</label>
            <textarea id="incident-body" name="body" maxlength="5000"></textarea>
            <label for="incident-ref">Outside reference number (for a referral)</label>
            <input id="incident-ref" type="text" name="external_reference" maxlength="120">
            <button type="submit">Save</button>
        </form>
    @endif
</x-safety::frame>
</x-app-layout>
