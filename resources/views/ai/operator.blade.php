<x-layouts::operations title="AI safeguarding log">
    <x-td.page-head title="AI safeguarding log" lede="Every assistant request, answered or refused. Opening one records your read in the audit log." />

    <x-td.stat-strip label="This month" :items="[
        ['label' => 'Requests sent to the model', 'value' => number_format($summary['requests_this_month'])],
        ['label' => 'Estimated cost (USD)', 'value' => '$'.number_format($summary['cost_this_month_micro_usd'] / 1000000, 2), 'hint' => 'Budget $'.number_format($summary['budget_micro_usd'] / 1000000, 0).'; the provider bill is the source of truth'],
        ['label' => 'Flagged', 'value' => number_format($summary['flagged_open'])],
    ]" />

    <x-td.tabs :items="collect(['flagged' => 'Flagged or reported', 'reported' => 'Reported', 'refused' => 'Refused', 'all' => 'All'])->map(fn ($label, $key) => ['label' => $label, 'href' => route('ai.operator.log', ['filter' => $key]), 'current' => $filter === $key])->values()->all()" label="Filter" />

    <x-td.panel title="Interactions" flush>
        @if ($rows->isEmpty())
            <x-td.state kind="empty" title="Nothing here">No interactions match this filter.</x-td.state>
        @else
            <x-td.table caption="AI interactions" caption-hidden dense>
                <thead><tr><th>#</th><th>Task</th><th>Role</th><th>Age band</th><th>Status</th><th>Flag</th><th>When</th></tr></thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td><a href="{{ route('ai.interactions.show', $row->id) }}">{{ $row->id }}</a></td>
                            <td>{{ str_replace('_', ' ', $row->task) }}</td>
                            <td>{{ $row->actor_role }}</td>
                            <td>{{ $row->age_band }}{{ $row->age_known ? '' : ' (age unknown)' }}</td>
                            <td>{{ $row->status }}{{ $row->refusal_code ? ': '.$row->refusal_code : '' }}</td>
                            <td>{{ $row->reported_at ? 'Reported' : '' }}{{ $row->flagged ? ' '.$row->flag_reason : '' }}</td>
                            <td class="is-muted">{{ $row->created_at?->format('j M H:i') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-td.table>
            <x-td.pagination :paginator="$rows" label="AI log pages" />
        @endif
    </x-td.panel>
</x-layouts::operations>
