@php $tz = config('tutor.timezone'); $sev = ['critical' => 'danger', 'high' => 'warning', 'medium' => 'neutral']; @endphp
<x-layouts::operations title="Incidents">
    @include('ops.safeguarding._head', ['title' => 'Incidents', 'lede' => 'An incident nobody acknowledges is raised one level each time its timer runs out (up to three) and every operator is told. Acknowledging takes ownership and stops the timer.'])

    <div class="grid gap-6">
        <x-td.panel flush>
            @if ($incidents->isEmpty())
                <x-td.state kind="success" title="No incidents">Critical reports open one automatically; you can also open one below.</x-td.state>
            @else
                <x-td.table caption="Incidents, open and most escalated first" caption-hidden>
                    <thead><tr><th scope="col">Reference</th><th scope="col">Severity</th><th scope="col">What</th><th scope="col">State</th><th scope="col">Timer</th></tr></thead>
                    <tbody>
                        @foreach ($incidents as $i)
                            <tr>
                                <td><a href="{{ route('ops.safeguarding.incidents.show', $i) }}">{{ $i->reference }}</a></td>
                                <td>@include('ops.partials.tag', ['text' => ucfirst($i->severity).($i->escalation_level > 0 ? ', raised '.$i->escalation_level.'×' : ''), 'tone' => $sev[$i->severity] ?? 'neutral'])</td>
                                <td>{{ $i->title }}</td>
                                <td>{{ ucfirst($i->status) }}{{ $i->owner ? ' · '.$i->owner->name : '' }}</td>
                                <td class="ops-num text-sm">
                                    @if ($i->status === 'open' && $i->respond_by)
                                        @if ($i->respond_by->isPast()) @include('ops.partials.tag', ['text' => 'Overdue since '.$i->respond_by->timezone($tz)->format('H:i'), 'tone' => 'danger'])
                                        @else Raises at {{ $i->respond_by->timezone($tz)->format('j M H:i') }} @endif
                                    @else — @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-td.table>
                @if ($incidents->hasPages())<div class="px-5 py-3"><x-td.pagination :paginator="$incidents" label="Incident pages" /></div>@endif
            @endif
        </x-td.panel>

        @if ($canAct)
            <x-td.panel title="Open an incident">
                <form method="POST" action="{{ route('ops.safeguarding.incidents.store') }}" class="ops-act">
                    @csrf
                    <x-td.field label="What happened, in a line" for="inc-title"><x-td.input name="title" id="inc-title" required maxlength="200" /></x-td.field>
                    <x-td.field label="Severity" for="inc-severity"><x-td.select name="severity" id="inc-severity" :options="['critical' => 'Critical, 1 hour', 'high' => 'High, 4 hours', 'medium' => 'Medium, 24 hours']" value="high" /></x-td.field>
                    <x-td.field label="Learner's email" for="inc-learner" optional><x-td.input name="learner_email" id="inc-learner" type="email" /></x-td.field>
                    <x-td.button type="submit" variant="primary">Open incident</x-td.button>
                </form>
            </x-td.panel>
        @endif
    </div>
</x-layouts::operations>
