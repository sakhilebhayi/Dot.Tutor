<x-app-layout>
<x-safety::frame>
    <p><a href="{{ route('safety.operator.queue') }}">Queue</a></p>
    <h1>Report #{{ $report['id'] }}: {{ $report['category_label'] }}</h1>
    <div class="panel">
        <div class="row"><span>Priority</span><span class="tag tag-{{ $report['priority'] }}">{{ ucfirst($report['priority']) }}</span></div>
        <div class="row"><span>Status</span><span class="tag tag-{{ $report['status'] }}">{{ ucfirst($report['status']) }}</span></div>
        <div class="row"><span>Reported by</span><span>{{ $report['reporter']['name'] ?? 'Unknown' }}</span></div>
        <div class="row"><span>About</span><span>{{ $report['reported_user']['name'] ?? 'Unknown' }}</span></div>
        @if ($report['incident'])<div class="row"><span>Incident</span><a href="{{ route('safety.operator.incidents.show', $report['incident']['id']) }}">{{ $report['incident']['reference'] }}</a></div>@endif
    </div>
    @if ($report['details'])<h2>What the reporter said</h2><p class="msg-body">{{ $report['details'] }}</p>@endif
    <h2>Content at the time of the report</h2>
    <div class="consent">{{ $report['snapshot']['body'] ?? json_encode($report['snapshot']) }}</div>
    @if ($report['original_body'])<h3>Before contact details were hidden (safeguarding staff only)</h3><div class="consent">{{ $report['original_body'] }}</div>@endif

    <form method="POST" action="{{ route('safety.operator.reports.act', $report['id']) }}" class="panel">
        @csrf
        <h2 style="margin-top:0">Act</h2>
        <label for="action">Action</label>
        <select id="action" name="action" required>
            <option value="hide">Hide the content</option>
            <option value="restore">Restore the content</option>
            <option value="warn">Warn the person</option>
            <option value="suspend">Suspend the person</option>
            <option value="escalate">Escalate to an incident</option>
            <option value="dismiss">Dismiss the report</option>
        </select>
        <label for="days">Suspension length in days (empty means until lifted)</label>
        <input id="days" type="number" name="days" min="1" max="3650">
        <label for="reason">Reason (recorded and shown in the audit trail)</label>
        <textarea id="reason" name="reason" required maxlength="500"></textarea>
        <button type="submit">Record action</button>
    </form>
</x-safety::frame>
</x-app-layout>
