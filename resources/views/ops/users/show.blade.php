@php
    use App\Domain\Ops\Access\OpsRole;
    use App\Domain\Safety\Enums\AgeBand;
    $tz = config('tutor.timezone');
    $band = AgeBand::from($account['age_band']);
@endphp
<x-layouts::operations :title="$person->name">
    <x-td.page-head :title="$person->name" lede="Account standing, age, guardians, restrictions and their history. Opening this page is recorded in the audit log.">
        <x-slot:actions><x-td.button :href="route('ops.users')" icon="arrow-left" variant="quiet">All users</x-td.button></x-slot:actions>
    </x-td.page-head>

    <div class="grid gap-6">
        @if ($account['status'] === 'suspended')
            <x-td.notice tone="danger" title="Suspended">Every gated action (book, pay, message, join a room, meet live, publish, upload) is refused{{ $account['suspended_until'] ? ' until '.\Illuminate\Support\Carbon::parse($account['suspended_until'])->timezone($tz)->format('j M Y, H:i') : ' until an operator lifts it' }}.</x-td.notice>
        @elseif ($account['status'] === 'limited')
            <x-td.notice tone="warning" title="Limited minor">This learner can read, report and use support, but cannot book, pay, message, join rooms or publish until a linked guardian consents.</x-td.notice>
        @endif

        <div class="td-panels td-panels--2">
            <x-td.panel title="Account">
                <dl class="ops-facts">
                    <dt>Email</dt><dd>{{ $person->email }}</dd>
                    <dt>Joined</dt><dd>{{ $person->created_at?->timezone($tz)->format('j M Y') }}</dd>
                    <dt>Age</dt><dd>{{ $account['age_known'] ? $band->label() : 'Not known ('.(config('safety.unknown_age_policy') === 'restrict' ? 'treated as a child' : 'treated as an adult until a date of birth is recorded').')' }}</dd>
                    <dt>Learning</dt><dd>{{ $learnerProfile ? collect([$learnerProfile->level, $learnerProfile->curriculum, $learnerProfile->country])->filter()->join(', ') ?: 'Profile started' : 'No learning profile' }}</dd>
                    <dt>Tutor</dt><dd>
                        @if ($tutor)
                            {{ ucfirst((string) $tutor->status) }}@if ($tutor->verification_level), {{ \App\Domain\Marketplace\Enums\VerificationLevel::tryFrom($tutor->verification_level)?->label() ?? $tutor->verification_level }}@endif
                            @if (app(\App\Domain\Ops\Access\OpsAccess::class)->can(request()->user(), \App\Domain\Ops\Access\OpsPermission::VERIFICATION_REVIEW))
                                · <a href="{{ route('ops.verification.tutor', $tutor) }}">Verification record</a>
                            @endif
                        @else
                            Not a tutor
                        @endif
                    </dd>
                    <dt>Operator</dt><dd>
                        @if ($roles === null) No
                        @elseif ($roles === []) Full access (no roles assigned yet)
                        @else {{ collect($roles)->map(fn ($r) => OpsRole::from($r)->label())->join(', ') }}
                        @endif
                        @if ($canRoles) · <a href="{{ route('ops.users.team') }}">Operators and roles</a>@endif
                    </dd>
                </dl>
            </x-td.panel>

            <x-td.panel title="Guardians">
                @if ($asLearner->isEmpty() && $asGuardian->isEmpty())
                    <p class="m-0 ops-muted">{{ $band->isMinor() ? 'No guardian has linked to this learner yet.' : 'Not linked to anyone as a learner or a guardian.' }}</p>
                @else
                    <ul class="ops-work">
                        @foreach ($asLearner as $link)
                            <li>
                                <div>
                                    <p class="ops-work__title">Guardian: {{ $link->guardian?->name ?? 'Deleted account' }} ({{ str_replace('_', ' ', $link->relationship) }})</p>
                                    <p class="ops-work__meta">{{ $link->status === 'active' ? 'Active since '.$link->created_at->timezone($tz)->format('j M Y') : 'Removed '.($link->revoked_at?->timezone($tz)->format('j M Y') ?? '').($link->revoke_reason ? ': '.$link->revoke_reason : '') }}</p>
                                </div>
                                @if ($canMinors && $link->status === 'active')
                                    <form method="POST" action="{{ route('ops.users.guardian-links.revoke', $link) }}" class="ops-act">
                                        @csrf
                                        <x-td.field label="Reason" for="revoke-{{ $link->id }}" error-bag="reason"><x-td.input name="reason" id="revoke-{{ $link->id }}" required /></x-td.field>
                                        <x-td.button type="submit" variant="danger" size="sm">Remove link</x-td.button>
                                    </form>
                                @endif
                            </li>
                        @endforeach
                        @foreach ($asGuardian as $link)
                            <li><div><p class="ops-work__title">Guardian of {{ $link->learner?->name ?? 'a deleted account' }}</p><p class="ops-work__meta">{{ ucfirst($link->status) }}</p></div></li>
                        @endforeach
                    </ul>
                @endif
            </x-td.panel>
        </div>

        @if ($canRestrict || $canMinors)
            <div class="td-panels td-panels--2">
                @if ($canRestrict)
                    <x-td.panel title="Warn or suspend">
                        <form method="POST" action="{{ route('ops.users.restrict', $person) }}" class="ops-act--stack ops-act">
                            @csrf
                            <x-td.field label="Reason (the person is told)" for="restrict-reason"><x-td.textarea name="reason" id="restrict-reason" rows="2" required /></x-td.field>
                            <x-td.field label="Suspension length in days" for="restrict-days" hint="Leave empty to suspend until lifted." optional><x-td.input name="days" id="restrict-days" type="number" min="1" max="3650" hint /></x-td.field>
                            <div class="flex flex-wrap gap-2">
                                <x-td.button type="submit" name="action" value="warn">Send a warning</x-td.button>
                                <x-td.button type="submit" name="action" value="suspend" variant="danger">Suspend</x-td.button>
                                @if ($account['status'] === 'suspended')<x-td.button type="submit" name="action" value="unsuspend" variant="quiet">Lift suspension</x-td.button>@endif
                            </div>
                        </form>
                    </x-td.panel>
                @endif
                @if ($canMinors)
                    <x-td.panel title="Verified date of birth">
                        <form method="POST" action="{{ route('ops.users.age', $person) }}" class="ops-act--stack ops-act">
                            @csrf
                            <p class="m-0 ops-muted text-sm">Record a date you have checked (for example a birth certificate seen by a safeguarding officer). It outranks the guardian's and the learner's own date.</p>
                            <x-td.field label="Date of birth" for="birth_date"><x-td.input name="birth_date" id="birth_date" type="date" required /></x-td.field>
                            <x-td.field label="How it was checked" for="age-note"><x-td.input name="note" id="age-note" required /></x-td.field>
                            <div><x-td.button type="submit">Record date of birth</x-td.button></div>
                        </form>
                        @if ($ageRecords->isNotEmpty())
                            <p class="mt-4 mb-0 text-sm ops-muted">Recorded: {{ $ageRecords->map(fn ($r) => str_replace('_', ' ', $r->source).' on '.$r->created_at->timezone($tz)->format('j M Y'))->join('; ') }}. Dates themselves are not shown here.</p>
                        @endif
                    </x-td.panel>
                @endif
            </div>
        @endif

        <x-td.panel title="Restrictions and moderation" flush>
            @if ($restrictions->isEmpty() && $actions->isEmpty())
                <x-td.state kind="empty" title="No warnings, suspensions or moderation actions">This person's record is clear.</x-td.state>
            @else
                <x-td.table caption="Restrictions and moderation actions, newest first" caption-hidden dense stack>
                    <thead><tr><th scope="col">When</th><th scope="col" data-stack="title">What</th><th scope="col" data-stack="wide">Reason</th><th scope="col">State</th></tr></thead>
                    <tbody>
                        @foreach ($restrictions as $r)
                            <tr>
                                <td class="ops-num text-sm">{{ $r->starts_at?->timezone($tz)->format('j M Y H:i') }}</td>
                                <td>{{ $r->kind === 'suspension' ? 'Suspension' : 'Warning' }}</td>
                                <td>{{ $r->reason }}</td>
                                <td class="text-sm">{{ $r->kind === 'warning' ? 'Sent' : ($r->lifted_at ? 'Lifted '.$r->lifted_at->timezone($tz)->format('j M Y') : ($r->ends_at ? ($r->ends_at->isPast() ? 'Ended' : 'Until '.$r->ends_at->timezone($tz)->format('j M Y')) : 'Until lifted')) }}</td>
                            </tr>
                        @endforeach
                        @foreach ($actions->whereNotIn('action', ['warn', 'suspend']) as $a)
                            <tr><td class="ops-num text-sm">{{ $a->created_at?->timezone($tz)->format('j M Y H:i') }}</td><td>{{ ucfirst($a->action) }}</td><td>{{ $a->reason }}</td><td class="text-sm">Recorded</td></tr>
                        @endforeach
                    </tbody>
                </x-td.table>
            @endif
        </x-td.panel>

        @if ($reports->isNotEmpty())
            <x-td.panel title="Reports about this person" flush>
                <ul class="ops-work">
                    @foreach ($reports as $report)
                        <li>
                            <div><p class="ops-work__title">{{ $report->category->label() }}</p><p class="ops-work__meta">{{ $report->created_at->timezone($tz)->format('j M Y') }} · {{ ucfirst($report->status) }}</p></div>
                            @if (app(\App\Domain\Ops\Access\OpsAccess::class)->allowsRoute(request()->user(), 'ops.safeguarding.reports.show'))<a href="{{ route('ops.safeguarding.reports.show', $report) }}">Open</a>@endif
                        </li>
                    @endforeach
                </ul>
            </x-td.panel>
        @endif

        @if ($audit !== null)
            @include('ops.partials.audit-table', ['entries' => $audit, 'caption' => 'Audit entries by or about this person, newest first'])
        @endif
    </div>
</x-layouts::operations>
