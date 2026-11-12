@php
    use App\Domain\Marketplace\Enums\VerificationLevel;
    $tz = config('tutor.timezone');
    $current = VerificationLevel::tryFrom((string) $tutor->verification_level);
    $name = $tutor->user?->name ?? 'Deleted account';
@endphp
<x-layouts::operations :title="$name.' · verification'">
    <x-td.page-head :title="$name" lede="The verification record: evidence, the level it supports, teaching languages, screening for work with minors, and every decision made.">
        <x-slot:actions><x-td.button :href="route('ops.verification', ['tab' => 'tutors'])" icon="arrow-left" variant="quiet">Verification</x-td.button></x-slot:actions>
    </x-td.page-head>

    <div class="grid gap-6">
        <div class="td-panels td-panels--2">
            <x-td.panel title="Standing">
                <dl class="ops-facts">
                    <dt>Profile</dt><dd>@include('ops.partials.tag', match ($tutor->status) {
                        'approved' => ['text' => 'Approved', 'tone' => 'success'],
                        'suspended' => ['text' => 'Suspended', 'tone' => 'danger'],
                        'rejected' => ['text' => 'Rejected', 'tone' => 'danger'],
                        default => ['text' => 'Application waiting', 'tone' => 'info'],
                    })</dd>
                    <dt>Level</dt><dd>{{ $current?->label() ?? 'Not yet verified' }}</dd>
                    <dt>Subjects</dt><dd>{{ $tutor->subjects->pluck('name')->join(', ') ?: 'None listed' }}</dd>
                    <dt>Work with minors</dt><dd>
                        @if ($screened)
                            @include('ops.partials.tag', ['text' => 'Screening current', 'tone' => 'success', 'icon' => 'shield-check'])
                        @else
                            @include('ops.partials.tag', ['text' => 'Not screened', 'tone' => 'warning'])
                            <span class="text-sm ops-muted">Missing: {{ collect($missingScreening)->map(fn ($k) => \App\Domain\Safety\Enums\ScreeningKind::tryFrom($k)?->label() ?? $k)->join(', ') ?: 'nothing' }}. Minors cannot book this tutor until a safeguarding officer records it.</span>
                        @endif
                    </dd>
                    @if ($tutor->rejected_reason || $tutor->suspended_reason)
                        <dt>Reason given</dt><dd>{{ $tutor->suspended_reason ?: $tutor->rejected_reason }}</dd>
                    @endif
                </dl>
                <form method="POST" action="{{ route('ops.verification.standing', $tutor) }}" class="ops-act mt-5">
                    @csrf
                    <x-td.field label="Reason (needed to reject or suspend)" for="standing-reason" optional><x-td.input name="reason" id="standing-reason" /></x-td.field>
                    @if ($tutor->status === 'pending')
                        <x-td.button type="submit" name="action" value="approve" variant="primary" size="sm">Approve</x-td.button>
                        <x-td.button type="submit" name="action" value="reject" size="sm">Reject</x-td.button>
                    @elseif ($tutor->status === 'approved')
                        <x-td.button type="submit" name="action" value="suspend" variant="danger" size="sm">Suspend tutor</x-td.button>
                    @elseif ($tutor->status === 'suspended')
                        <x-td.button type="submit" name="action" value="reinstate" size="sm">Reinstate</x-td.button>
                    @endif
                </form>
            </x-td.panel>

            <x-td.panel title="Verification level">
                <form method="POST" action="{{ route('ops.verification.level', $tutor) }}" class="ops-act ops-act--stack">
                    @csrf
                    <p class="m-0 text-sm ops-muted">A level is accepted only when a verified record of a supporting kind exists. Removing the level shows "Not yet verified".</p>
                    <x-td.field label="Level" for="level">
                        <x-td.select name="level" id="level" :value="$current?->value" placeholder="No level (not yet verified)" :options="collect($levels)->mapWithKeys(fn ($l) => [$l->value => $l->label()])->all()" />
                    </x-td.field>
                    <x-td.field label="Reason" for="level-reason"><x-td.input name="reason" id="level-reason" required minlength="5" /></x-td.field>
                    <div><x-td.button type="submit">Save level</x-td.button></div>
                </form>
            </x-td.panel>
        </div>

        <x-td.panel title="Qualifications and evidence" flush>
            @if ($qualifications->isEmpty())
                <x-td.state kind="empty" title="No qualifications recorded">The tutor adds them from their workspace; evidence makes them reviewable.</x-td.state>
            @else
                <ul class="ops-work">
                    @foreach ($qualifications as $q)
                        <li>
                            <div class="grid gap-2">
                                <p class="ops-work__title">{{ $q->title }} <span class="ops-muted" style="font-weight:400">· {{ $q->kindEnum()->label() }}{{ $q->institution ? ', '.$q->institution : '' }}</span></p>
                                <p class="ops-work__meta">
                                    @include('ops.partials.tag', match ($q->status) {
                                        'verified' => ['text' => 'Verified', 'tone' => 'success'],
                                        'submitted' => ['text' => 'Waiting for review', 'tone' => 'info'],
                                        'rejected' => ['text' => 'Rejected', 'tone' => 'danger'],
                                        default => ['text' => 'No evidence sent', 'icon' => 'circle-dashed'],
                                    })
                                    @if ($q->review_note) <span>{{ $q->review_note }}</span>@endif
                                </p>
                                @if ($q->evidence->isNotEmpty())
                                    <p class="m-0 text-sm">Evidence: @foreach ($q->evidence as $file)<a href="{{ route('marketplace.api.operator.evidence', $file) }}">{{ $file->original_name }}</a>{{ $loop->last ? '' : ', ' }}@endforeach</p>
                                @endif
                                @if (in_array($q->status, ['submitted', 'verified'], true))
                                    <form method="POST" action="{{ route('ops.verification.decide', $q) }}" class="ops-act">
                                        @csrf
                                        <x-td.field label="Note" for="q-note-{{ $q->id }}"><x-td.input name="note" id="q-note-{{ $q->id }}" required minlength="5" /></x-td.field>
                                        @if ($q->status === 'submitted')
                                            <x-td.button type="submit" name="decision" value="verify" variant="primary" size="sm">Verify</x-td.button>
                                            <x-td.button type="submit" name="decision" value="reject" size="sm">Reject</x-td.button>
                                        @else
                                            <x-td.button type="submit" name="decision" value="revoke" variant="danger" size="sm">Withdraw verification</x-td.button>
                                        @endif
                                    </form>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-td.panel>

        <div class="td-panels td-panels--2">
            <x-td.panel title="Decisions" flush>
                @if ($decisions->isEmpty())
                    <x-td.state kind="empty" title="No decisions yet">Every verification decision is kept here and cannot be edited.</x-td.state>
                @else
                    <x-td.table caption="Verification decisions, newest first" caption-hidden dense stack>
                        <thead><tr><th scope="col">When</th><th scope="col" data-stack="title">What</th><th scope="col">Change</th><th scope="col" data-stack="wide">Reason</th></tr></thead>
                        <tbody>
                            @foreach ($decisions as $d)
                                <tr>
                                    <td class="ops-num text-sm" style="white-space:nowrap">{{ $d->created_at?->timezone($tz)->format('j M Y H:i') }}</td>
                                    <td>{{ $d->subject === 'level' ? 'Level' : 'Qualification' }}</td>
                                    <td class="text-sm">{{ str_replace('_', ' ', $d->from_value ?? 'none') }} to {{ str_replace('_', ' ', $d->to_value ?? 'none') }}</td>
                                    <td class="text-sm">{{ $d->reason }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </x-td.table>
                @endif
            </x-td.panel>
            <x-td.panel title="Teaching languages">
                @forelse ($languages as $ul)
                    <p class="m-0 mb-2"><span lang="{{ $ul->language?->code }}">{{ $ul->language?->name_native ?? $ul->language?->name_en }}</span> · {{ $ul->proficiency?->label() ?? 'level not stated' }} · {{ $ul->verified_at ? 'verified' : 'self-declared' }}</p>
                @empty
                    <p class="m-0 ops-muted">No teaching language listed.</p>
                @endforelse
                <p class="mt-3 mb-0 text-sm"><a href="{{ route('ops.verification', ['tab' => 'languages']) }}">Verify teaching languages</a></p>
            </x-td.panel>
        </div>
    </div>
</x-layouts::operations>
