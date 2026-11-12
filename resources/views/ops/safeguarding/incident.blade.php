@php $tz = config('tutor.timezone'); @endphp
<x-layouts::operations title="{{ $incident->reference }}">
    @include('ops.safeguarding._head', ['title' => $incident->reference.' · '.$incident->title, 'lede' => ucfirst($incident->severity).' incident, opened '.$incident->created_at->timezone($tz)->format('j M Y, H:i').'. The timeline cannot be edited.'])

    <div class="grid gap-6">
        @if ($incident->status === 'open')
            <x-td.notice :tone="$incident->respond_by?->isPast() ? 'danger' : 'warning'" title="Nobody owns this incident yet">
                {{ $incident->respond_by?->isPast() ? 'Its timer ran out at '.$incident->respond_by->timezone($tz)->format('H:i').'; it is raised again every time the timer runs out.' : 'It is raised a level at '.$incident->respond_by?->timezone($tz)->format('j M H:i').' unless someone acknowledges it.' }}
            </x-td.notice>
        @endif

        <div class="td-panels td-panels--2">
            <x-td.panel title="Timeline" flush>
                @if ($events->isEmpty())
                    <x-td.state kind="empty" title="No events yet" />
                @else
                    <div class="p-5">
                        <x-td.timeline>
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
