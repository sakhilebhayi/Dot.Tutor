@php
    $tz = \App\Domain\Student\Support\Times::zone();
    $tabs = [
        ['label' => 'Evidence to review', 'href' => route('ops.verification', ['tab' => 'evidence']), 'current' => $tab === 'evidence', 'count' => $counts['evidence']],
        ['label' => 'Tutor applications', 'href' => route('ops.verification', ['tab' => 'applications']), 'current' => $tab === 'applications', 'count' => $counts['applications']],
        ['label' => 'Teaching languages', 'href' => route('ops.verification', ['tab' => 'languages']), 'current' => $tab === 'languages', 'count' => $counts['languages']],
        ['label' => 'Tutors', 'href' => route('ops.verification', ['tab' => 'tutors']), 'current' => $tab === 'tutors'],
    ];
@endphp
<x-layouts::operations title="Tutor verification">
    <x-td.page-head title="Tutor verification" lede="Labels appear on a storefront only after a recorded review of real evidence. A peer tutor is never shown as a qualified or professional educator." />
    <x-td.tabs :items="$tabs" label="Verification queues" class="mb-5" />

    @if ($tab === 'evidence')
        <x-td.notice tone="info" class="mb-5">Evidence files are scanned by nobody: the host has no virus scanner. Open them in a sandboxed viewer and never run anything inside them.</x-td.notice>
        <x-td.panel flush>
            @if ($queue->isEmpty())
                <x-td.state kind="success" title="No evidence is waiting">New submissions appear here, oldest first.</x-td.state>
            @else
                <ul class="ops-work">
                    @foreach ($queue as $q)
                        <li>
                            <div class="grid gap-2">
                                <p class="ops-work__title">{{ $q->title }} <span class="ops-muted" style="font-weight:400">· {{ $q->kindEnum()->label() }}{{ $q->institution ? ', '.$q->institution : '' }}{{ $q->year_awarded ? ', '.$q->year_awarded : '' }}</span></p>
                                <p class="ops-work__meta">{{ $q->tutorProfile?->user?->name ?? 'Deleted account' }} · sent {{ $q->submitted_at?->timezone($tz)->format('j M Y') ?? 'without a date' }} ·
                                    <a href="{{ route('ops.verification.tutor', $q->tutor_profile_id) }}">Verification record</a></p>
                                @if ($q->evidence->isNotEmpty())
                                    <p class="m-0 text-sm">Evidence:
                                        @foreach ($q->evidence as $file)
                                            <a href="{{ route('marketplace.api.operator.evidence', $file) }}">{{ $file->original_name }}</a>{{ $loop->last ? '' : ',' }}
                                        @endforeach
                                    </p>
                                @endif
                                <form method="POST" action="{{ route('ops.verification.decide', $q) }}" class="ops-act">
                                    @csrf
                                    <x-td.field label="What you checked, or why not" for="note-{{ $q->id }}" error-bag="note"><x-td.input name="note" id="note-{{ $q->id }}" required minlength="5" /></x-td.field>
                                    <x-td.button type="submit" name="decision" value="verify" variant="primary" size="sm">Verify</x-td.button>
                                    <x-td.button type="submit" name="decision" value="reject" size="sm">Reject</x-td.button>
                                </form>
                            </div>
                        </li>
                    @endforeach
                </ul>
                @if ($queue->hasPages())<div class="px-5 py-3"><x-td.pagination :paginator="$queue" label="Evidence pages" /></div>@endif
            @endif
        </x-td.panel>
    @elseif ($tab === 'applications')
        <x-td.panel flush>
            @if ($applications->isEmpty())
                <x-td.state kind="success" title="No applications are waiting">New tutor applications appear here, oldest first.</x-td.state>
            @else
                <ul class="ops-work">
                    @foreach ($applications as $p)
                        <li>
                            <div class="grid gap-2">
                                <p class="ops-work__title"><a href="{{ route('ops.verification.tutor', $p) }}">{{ $p->user?->name ?? 'Deleted account' }}</a> <span class="ops-muted" style="font-weight:400">· applied {{ $p->created_at?->timezone($tz)->format('j M Y') }}</span></p>
                                <p class="ops-work__meta">{{ $p->headline ?: 'No headline' }} · {{ $p->subjects->pluck('name')->join(', ') ?: 'no subjects listed' }}</p>
                                <form method="POST" action="{{ route('ops.verification.standing', $p) }}" class="ops-act">
                                    @csrf
                                    <x-td.field label="Reason (needed to reject)" for="app-reason-{{ $p->id }}" error-bag="reason" optional><x-td.input name="reason" id="app-reason-{{ $p->id }}" /></x-td.field>
                                    <x-td.button type="submit" name="action" value="approve" variant="primary" size="sm">Approve</x-td.button>
                                    <x-td.button type="submit" name="action" value="reject" size="sm">Reject</x-td.button>
                                </form>
                            </div>
                        </li>
                    @endforeach
                </ul>
                @if ($applications->hasPages())<div class="px-5 py-3"><x-td.pagination :paginator="$applications" label="Application pages" /></div>@endif
            @endif
        </x-td.panel>
    @elseif ($tab === 'languages')
        <x-td.panel flush>
            @if ($languages->isEmpty())
                <x-td.state kind="empty" title="No tutor has listed a teaching language">Tutors list the languages they teach in from their workspace.</x-td.state>
            @else
                <x-td.table caption="Tutors' teaching languages, unverified first" caption-hidden dense stack>
                    <thead><tr><th scope="col">Tutor</th><th scope="col">Teaches in</th><th scope="col">Declared level</th><th scope="col">State</th><th scope="col" data-stack="action"><span class="td-sr">Action</span></th></tr></thead>
                    <tbody>
                        @foreach ($languages as $ul)
                            <tr>
                                <td>{{ $ul->user?->name }}</td>
                                <td lang="{{ $ul->language?->code }}">{{ $ul->language?->name_native ?? $ul->language?->name_en }} <span class="ops-muted text-sm" lang="en">{{ $ul->language?->name_en }}</span></td>
                                <td>{{ $ul->proficiency?->label() ?? 'Not stated' }}</td>
                                <td>@include('ops.partials.tag', $ul->verified_at ? ['text' => 'Verified '.$ul->verified_at->timezone($tz)->format('j M Y'), 'tone' => 'success'] : ['text' => 'Self-declared', 'icon' => 'circle-dashed'])</td>
                                <td>
                                    <form method="POST" action="{{ route($ul->verified_at ? 'operator.academic.teaching-languages.unverify' : 'operator.academic.teaching-languages.verify', $ul) }}">
                                        @csrf
                                        <x-td.button type="submit" size="sm" :variant="$ul->verified_at ? 'quiet' : 'secondary'">{{ $ul->verified_at ? 'Remove verification' : 'Mark verified' }}</x-td.button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-td.table>
                @if ($languages->hasPages())<div class="px-5 py-3"><x-td.pagination :paginator="$languages" label="Language pages" /></div>@endif
            @endif
        </x-td.panel>
        <p class="mt-3 text-sm ops-muted">Mark a language verified only after you heard the tutor teach in it (an interview or a recorded lesson) or saw a qualification taught in it.</p>
    @else
        <x-td.panel flush>
            @if ($tutors->isEmpty())
                <x-td.state kind="empty" title="No approved tutors yet">Approved and suspended tutors are listed here.</x-td.state>
            @else
                <x-td.table caption="Approved and suspended tutors" caption-hidden dense stack>
                    <thead><tr><th scope="col">Tutor</th><th scope="col">State</th><th scope="col">Verification level</th></tr></thead>
                    <tbody>
                        @foreach ($tutors as $t)
                            <tr>
                                <td><a href="{{ route('ops.verification.tutor', $t) }}">{{ $t->user?->name ?? 'Deleted account' }}</a></td>
                                <td>@include('ops.partials.tag', $t->status === 'suspended' ? ['text' => 'Suspended', 'tone' => 'danger'] : ['text' => 'Approved', 'tone' => 'success'])</td>
                                <td>{{ \App\Domain\Marketplace\Enums\VerificationLevel::tryFrom((string) $t->verification_level)?->label() ?? 'Not yet verified' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-td.table>
                @if ($tutors->hasPages())<div class="px-5 py-3"><x-td.pagination :paginator="$tutors" label="Tutor pages" /></div>@endif
            @endif
        </x-td.panel>
    @endif
</x-layouts::operations>
