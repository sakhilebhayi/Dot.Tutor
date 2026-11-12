@php
    use App\Domain\Safety\Enums\ReportPriority;
    $tz = config('tutor.timezone');
    $priorityTone = ['critical' => 'danger', 'high' => 'warning', 'normal' => 'neutral', 'low' => 'neutral'];
    $priorityText = ['critical' => 'Child safety · 1 hour', 'high' => 'High · 4 hours', 'normal' => 'Normal · 24 hours', 'low' => 'Low · 72 hours'];
    $critical = $reports->filter(fn ($r) => $r->priority === ReportPriority::Critical);
@endphp
<x-layouts::operations title="Safeguarding reports">
    @include('ops.safeguarding._head', ['title' => 'Safeguarding', 'lede' => 'Reports from every part of Dot.Tutor, most urgent first. A report of inappropriate contact with a minor opens a critical incident at once and sits at the top until someone acts.'])

    <x-td.tabs class="mb-5" label="Report state" :items="[
        ['label' => 'Open', 'href' => route('ops.safeguarding'), 'current' => $show === 'open'],
        ['label' => 'Closed', 'href' => route('ops.safeguarding', ['show' => 'closed']), 'current' => $show === 'closed'],
    ]" />

    @if ($show === 'open' && $critical->isNotEmpty())
        <x-td.notice tone="danger" title="{{ $critical->count() === 1 ? 'A child-safety report is open' : $critical->count().' child-safety reports are open' }}" class="mb-5">
            Each one has a critical incident with a one-hour timer. Open the report, protect the child first (hide the content, suspend the account), then acknowledge the incident.
        </x-td.notice>
    @endif

    <x-td.panel flush>
        @if ($reports->isEmpty())
            <x-td.state :kind="$show === 'open' ? 'success' : 'empty'" :title="$show === 'open' ? 'No open reports' : 'No closed reports yet'">
                {{ $show === 'open' ? 'Reports filed by learners, guardians and tutors appear here with their response time.' : 'Reports appear here once actioned or dismissed.' }}
            </x-td.state>
        @else
            <x-td.table caption="{{ $show === 'open' ? 'Open reports, most urgent first' : 'Closed reports, latest first' }}" caption-hidden>
                <thead><tr><th scope="col">Priority</th><th scope="col">What</th><th scope="col">About</th><th scope="col">{{ $show === 'open' ? 'Respond by' : 'Outcome' }}</th><th scope="col"><span class="td-sr">Open</span></th></tr></thead>
                <tbody>
                    @foreach ($reports as $r)
                        <tr>
                            <td>@include('ops.partials.tag', ['text' => $priorityText[$r->priority->value], 'tone' => $priorityTone[$r->priority->value]])</td>
                            <td>{{ $r->category->label() }}@if ($r->involves_minor) <span class="text-sm ops-muted">· a minor is involved</span>@endif
                                <div class="text-sm ops-muted">{{ class_basename(str_replace('.', '\\', (string) $r->reportable_type)) }} · filed {{ $r->created_at->timezone($tz)->format('j M H:i') }}</div></td>
                            <td>{{ $r->reportedUser?->name ?? '—' }}</td>
                            <td class="ops-num text-sm">
                                @if ($show === 'open')
                                    {{ $r->respond_by->timezone($tz)->format('j M H:i') }}
                                    @if ($r->respond_by->isPast()) @include('ops.partials.tag', ['text' => 'Overdue', 'tone' => 'danger']) @endif
                                    @if ($r->incident) <div><a href="{{ route('ops.safeguarding.incidents.show', $r->incident_id) }}">{{ $r->incident->reference }}</a></div>@endif
                                @else
                                    {{ ucfirst($r->status) }}
                                @endif
                            </td>
                            <td><x-td.button :href="route('ops.safeguarding.reports.show', $r)" size="sm">Open</x-td.button></td>
                        </tr>
                    @endforeach
                </tbody>
            </x-td.table>
        @endif
    </x-td.panel>
</x-layouts::operations>
