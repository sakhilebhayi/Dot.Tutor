@php
    $tz = \App\Domain\Student\Support\Times::zone();
    // The response deadline in words, from the real respond_by; resources/js/ds/countdown.js keeps it current (D-2405).
    $left = function (\Carbon\CarbonInterface $at): string {
        $mins = (int) floor(now()->diffInSeconds($at, false) / 60);
        $span = fn (int $m) => $m >= 120 ? intdiv($m, 60).' h '.($m % 60).' min' : $m.' min';

        return $mins >= 0 ? 'Acknowledge within '.$span($mins) : 'Overdue by '.$span(-$mins);
    };
@endphp
<x-layouts::operations title="{{ $incident->reference }}">
    @include('ops.safeguarding._head', ['title' => $incident->reference.' · '.$incident->title, 'lede' => ucfirst($incident->severity).' incident, opened '.$incident->created_at->timezone($tz)->format('j M Y, H:i').'. The timeline cannot be edited.'])

    <div class="grid gap-6">
        @if ($incident->status === 'open')
            <x-td.notice :tone="$incident->respond_by?->isPast() ? 'danger' : 'warning'" title="Nobody owns this incident yet">
                @if ($incident->respond_by)
                    <p class="m-0"><strong><time datetime="{{ $incident->respond_by->toIso8601String() }}" data-countdown data-countdown-before="Acknowledge within" data-countdown-after="Overdue by">{{ $left($incident->respond_by) }}</time></strong>
                        ({{ $incident->respond_by->isPast() ? 'the timer ran out at' : 'by' }} {{ \App\Domain\Student\Support\Times::stamp($incident->respond_by, 'j M H:i') }}).</p>
                    <p class="m-0">{{ $incident->respond_by->isPast() ? 'It is raised again every time the timer runs out.' : 'It is raised a level then unless someone acknowledges it.' }}</p>
                @else
                    No response deadline is set.
                @endif
            </x-td.notice>
        @endif

        <x-td.panel title="Protect first" flush>
            @if ($canAct)<p class="m-0 px-5 pt-4 text-sm ops-muted">Hide what was reported and suspend the reported person before anything else. Each action is recorded on the report and in the audit log.</p>@endif
            @if ($reported->isEmpty())
                <p class="m-0 p-5">No report is linked to this incident, so nobody is recorded as reported. To suspend someone, open their account from <a class="td-link" href="{{ route('ops.users') }}">Users</a>; content is hidden from the report it was reported in.</p>
            @else
                <ul class="ops-work">
                    @foreach ($reported as $row)
                        @php $rep = $row['report']; $person = $row['person']; @endphp
                        <li>
                            <div class="grid gap-3">
                                <p class="ops-work__title">
                                    Reported person:
                                    @if ($person)
                                        <a href="{{ route('ops.users.show', $person) }}">{{ $person->name }}</a>
                                        @if ($row['minor']) @include('ops.partials.tag', ['text' => 'Under 18', 'tone' => 'warning']) @endif
                                        @if ($row['suspended']) @include('ops.partials.tag', ['text' => 'Suspended', 'tone' => 'danger']) @endif
                                    @else
                                        <span class="ops-muted" style="font-weight:400">nobody named in <a href="{{ route('ops.safeguarding.reports.show', $rep) }}">report {{ $rep->id }}</a></span>
                                    @endif
                                </p>
                                <p class="ops-work__meta">
                                    <a href="{{ route('ops.safeguarding.reports.show', $rep) }}">Report {{ $rep->id }}</a> · {{ $rep->category->label() }}
                                    · {{ $row['canHide'] ? ($row['hidden'] ? 'content hidden '.$row['hiddenAt']?->timezone($tz)->format('j M H:i') : 'content still visible') : 'this kind of content is hidden in its own area, not from here' }}
                                </p>
                                @if ($canAct && (($row['canHide'] && ! $row['hidden']) || ($person && ! $row['suspended'])))
                                    <form method="POST" action="{{ route('ops.safeguarding.reports.act', $rep) }}" class="ops-act ops-act--stack">
                                        @csrf
                                        <x-td.field label="Reason (kept with the action; a suspended person is told)" for="protect-reason-{{ $rep->id }}"><x-td.input name="reason" id="protect-reason-{{ $rep->id }}" required /></x-td.field>
                                        <div class="flex flex-wrap items-end gap-2">
                                            @if ($row['canHide'] && ! $row['hidden'])
                                                <x-td.button type="submit" name="action" value="hide" variant="primary">Hide the reported content</x-td.button>
                                            @endif
                                            @if ($person && ! $row['suspended'])
                                                <x-td.button type="submit" name="action" value="suspend" variant="danger" icon="lock" data-confirm="Suspend {{ $person->name }}? They are told the reason and cannot use Dot.Tutor until the suspension is lifted.">Suspend {{ $person->name }}</x-td.button>
                                            @endif
                                        </div>
                                        <p class="m-0 text-sm ops-muted">A suspension has no end date from here; lift it from the person's account.</p>
                                    </form>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-td.panel>

        <div class="td-panels td-panels--2">
            <x-td.panel title="Timeline" flush>
                @if ($events->isEmpty())
                    <x-td.state kind="empty" title="No events yet" />
                @else
                    <div class="p-5">
                        <x-td.timeline label="Incident timeline">
                            @foreach ($events as $e)
                                <x-td.timeline-item :title="ucfirst(str_replace('_', ' ', $e->type))" state="done" :meta="$e->created_at->timezone($tz)->format('j M Y H:i').($e->actor_id ? ' · '.($actors[$e->actor_id] ?? 'User '.$e->actor_id) : ' · system')">
                                    @if ($e->body)<p class="m-0 whitespace-pre-line">{{ $e->body }}</p>@endif
                                </x-td.timeline-item>
                            @endforeach
                        </x-td.timeline>
                    </div>
                @endif
            </x-td.panel>
            <x-td.panel title="Details">
                <dl class="ops-facts">
                    <dt>State</dt><dd>{{ ucfirst($incident->status) }}</dd>
                    <dt>Owner</dt><dd>{{ $incident->owner?->name ?? 'Nobody yet' }}</dd>
                    <dt>Raised</dt><dd>{{ $incident->escalation_level }} {{ $incident->escalation_level === 1 ? 'time' : 'times' }}</dd>
                    <dt>Learner</dt><dd>@if ($learner)<a href="{{ route('ops.users.show', $learner) }}">{{ $learner->name }}</a>@else Not named @endif</dd>
                    <dt>Outside referral</dt><dd>{{ $incident->external_reference ?: 'None recorded' }}</dd>
                    <dt>Reports</dt><dd>@forelse ($incident->reports as $r)<a href="{{ route('ops.safeguarding.reports.show', $r) }}">Report {{ $r->id }}</a>{{ $loop->last ? '' : ', ' }}@empty None @endforelse</dd>
                    @if ($incident->outcome)<dt>Outcome</dt><dd>{{ $incident->outcome }}</dd>@endif
                </dl>
            </x-td.panel>
        </div>

        @if ($canAct && $incident->status !== 'closed')
            <x-td.panel title="Work the incident">
                <form method="POST" action="{{ route('ops.safeguarding.incidents.update', $incident) }}" class="ops-act ops-act--stack">
                    @csrf
                    @if ($incident->status === 'open')
                        <div><x-td.button type="submit" name="action" value="acknowledge" variant="primary">Acknowledge and take ownership</x-td.button></div>
                    @endif
                    <x-td.field label="Note, referral details or outcome" for="inc-body"><x-td.textarea name="body" id="inc-body" rows="3" /></x-td.field>
                    <x-td.field label="Outside reference (for example a SAPS case number)" for="inc-ref" optional><x-td.input name="external_reference" id="inc-ref" /></x-td.field>
                    <p class="m-0 text-sm ops-muted">Recording a referral sends nothing from the platform: contact SAPS, the Department of Social Development or the Film and Publication Board yourself, then record it here.</p>
                    <div class="flex flex-wrap gap-2">
                        <x-td.button type="submit" name="action" value="note">Add note</x-td.button>
                        <x-td.button type="submit" name="action" value="refer">Record referral</x-td.button>
                        <x-td.button type="submit" name="action" value="close" variant="danger">Close with this outcome</x-td.button>
                    </div>
                </form>
            </x-td.panel>
        @endif
    </div>
</x-layouts::operations>
