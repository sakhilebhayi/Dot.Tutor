@php $tz = config('tutor.timezone'); @endphp
<x-layouts::operations title="Report {{ $report->id }}">
    @include('ops.safeguarding._head', ['title' => $report->category->label(), 'lede' => 'Report '.$report->id.', filed '.$report->created_at->timezone($tz)->format('j M Y, H:i').'. Opening it is recorded in the audit log.'])

    <div class="grid gap-6">
        <div class="td-panels td-panels--2">
            <x-td.panel title="The report">
                <dl class="ops-facts">
                    <dt>Priority</dt><dd>{{ ucfirst($report->priority->value) }}, respond by {{ $report->respond_by->timezone($tz)->format('j M Y H:i') }}@if ($report->isOpen() && $report->respond_by->isPast()) @include('ops.partials.tag', ['text' => 'Overdue', 'tone' => 'danger'])@endif</dd>
                    <dt>State</dt><dd>{{ ucfirst($report->status) }}{{ $report->resolution ? ': '.$report->resolution : '' }}</dd>
                    <dt>Filed by</dt><dd>{{ $report->reporter?->name ?? 'Deleted account' }}</dd>
                    <dt>About</dt><dd>@if ($report->reportedUser)<a href="{{ route('ops.users.show', $report->reportedUser) }}">{{ $report->reportedUser->name }}</a>@else — @endif</dd>
                    <dt>Content</dt><dd>{{ class_basename(str_replace('.', '\\', (string) $report->reportable_type)) }} {{ $report->reportable_id }}</dd>
                    <dt>A minor involved</dt><dd>{{ $report->involves_minor ? 'Yes' : 'No' }}</dd>
                    <dt>Incident</dt><dd>@if ($report->incident)<a href="{{ route('ops.safeguarding.incidents.show', $report->incident) }}">{{ $report->incident->reference }}</a> ({{ $report->incident->status }})@else None @endif</dd>
                </dl>
                @if ($report->details)
                    <h3 class="td-label mt-5 mb-2">What the reporter wrote</h3>
                    <p class="m-0 whitespace-pre-line" style="max-width: var(--td-measure)">{{ $report->details }}</p>
                @endif
            </x-td.panel>

            <x-td.panel title="Evidence">
                @if ($message)
                    <p class="m-0 mb-2 text-sm ops-muted">The reported message as participants see it{{ $message->hidden_at ? ' (hidden by a moderator)' : '' }}:</p>
                    <p class="m-0 whitespace-pre-line p-3 bg-td-sunken">{{ $message->body }}</p>
                    @if ($message->redactions)
                        <details class="mt-4"><summary class="cursor-pointer">Original text, before contact details were hidden</summary>
                            <p class="mt-2 mb-0 whitespace-pre-line p-3 bg-td-sunken">{{ $message->original_body }}</p></details>
                    @endif
                @elseif ($report->snapshot)
                    <p class="m-0 mb-2 text-sm ops-muted">A copy taken when the report was filed:</p>
                    <dl class="ops-facts">
                        @foreach ((array) $report->snapshot as $key => $value)
                            <dt>{{ ucfirst(str_replace('_', ' ', (string) $key)) }}</dt><dd class="whitespace-pre-line">{{ is_scalar($value) || $value === null ? $value : json_encode($value) }}</dd>
                        @endforeach
                    </dl>
                @else
                    <p class="m-0 ops-muted">No copy of the content was kept with this report.</p>
                @endif
            </x-td.panel>
        </div>

        @if ($canAct && $report->isOpen())
            <x-td.panel title="Act on it">
                <form method="POST" action="{{ route('ops.safeguarding.reports.act', $report) }}" class="ops-act ops-act--stack">
                    @csrf
                    <x-td.field label="Reason (kept with the action; a warned or suspended person is told)" for="act-reason"><x-td.textarea name="reason" id="act-reason" rows="2" required /></x-td.field>
                    <div class="flex flex-wrap gap-4">
                        <x-td.field label="Suspension days" for="act-days" optional class="w-40"><x-td.input name="days" id="act-days" type="number" min="1" max="3650" /></x-td.field>
                        <x-td.field label="Incident severity" for="act-severity" optional class="w-48"><x-td.select name="severity" id="act-severity" :options="['critical' => 'Critical, 1 hour', 'high' => 'High, 4 hours', 'medium' => 'Medium, 24 hours']" placeholder="High" /></x-td.field>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <x-td.button type="submit" name="action" value="hide" variant="primary">Hide the content</x-td.button>
                        <x-td.button type="submit" name="action" value="warn">Warn the person</x-td.button>
                        <x-td.button type="submit" name="action" value="suspend" variant="danger">Suspend the person</x-td.button>
                        <x-td.button type="submit" name="action" value="escalate">Escalate to an incident</x-td.button>
                        <x-td.button type="submit" name="action" value="restore" variant="quiet">Restore the content</x-td.button>
                        <x-td.button type="submit" name="action" value="dismiss" variant="quiet">Dismiss</x-td.button>
                    </div>
                </form>
            </x-td.panel>
        @endif

        <x-td.panel title="Actions taken" flush>
            @if ($actions->isEmpty())
                <x-td.state kind="empty" title="No action yet">Every action is kept here and in the audit log.</x-td.state>
            @else
                <x-td.table caption="Moderation actions on this report" caption-hidden dense>
                    <thead><tr><th scope="col">When</th><th scope="col">Action</th><th scope="col">Reason</th></tr></thead>
                    <tbody>@foreach ($actions as $a)<tr><td class="ops-num text-sm">{{ $a->created_at?->timezone($tz)->format('j M Y H:i') }}</td><td>{{ ucfirst($a->action) }}</td><td>{{ $a->reason }}</td></tr>@endforeach</tbody>
                </x-td.table>
            @endif
        </x-td.panel>
    </div>
</x-layouts::operations>
