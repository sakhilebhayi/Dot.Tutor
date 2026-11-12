@php
    use App\Domain\Safety\Enums\ScreeningKind;
    $tz = config('tutor.timezone');
    $label = fn ($k) => ScreeningKind::tryFrom($k instanceof \BackedEnum ? $k->value : (string) $k)?->label() ?? $k;
    $unscreened = $tutors->filter(fn ($t) => $t['missing'] !== []);
@endphp
<x-layouts::operations title="Screening">
    @include('ops.safeguarding._head', ['title' => 'Screening for work with minors', 'lede' => 'An operator checks outside the platform and records the result here with evidence. Nothing is checked automatically: neither register offers a public lookup.'])

    <div class="grid gap-6">
        <x-td.notice tone="info">Required for work with minors: {{ collect($required)->map($label)->join(' and ') }}. {{ $fourEyes ? 'A second operator must review each record; you cannot review your own.' : 'Second review is switched off (SAFETY_SCREENING_FOUR_EYES=false).' }}</x-td.notice>

        <x-td.panel title="Waiting for a second reviewer" flush>
            @if ($pending->isEmpty())
                <x-td.state kind="success" title="Nothing to review" />
            @else
                <ul class="ops-work">
                    @foreach ($pending as $check)
                        <li>
                            <div class="grid gap-2">
                                <p class="ops-work__title">{{ $check->user?->name }} · {{ $label($check->kind) }}</p>
                                <p class="ops-work__meta">Checked {{ $check->checked_on?->format('j M Y') }}{{ $check->expires_on ? ', expires '.$check->expires_on->format('j M Y') : '' }}{{ $check->reference ? ', reference '.$check->reference : '' }} ·
                                    @if ($check->evidence_path)<a href="{{ route('safety.operator.screening.evidence', $check) }}">{{ $check->evidence_name ?: 'Evidence' }}</a>@else no evidence attached, so it cannot be cleared @endif</p>
                                <form method="POST" action="{{ route('ops.safeguarding.screening.review', $check) }}" class="ops-act">
                                    @csrf
                                    <x-td.field label="Note" for="sc-note-{{ $check->id }}" optional><x-td.input name="note" id="sc-note-{{ $check->id }}" /></x-td.field>
                                    <x-td.button type="submit" name="decision" value="cleared" variant="primary" size="sm" :disabled="$check->recorded_by_id === auth()->id() && $fourEyes">Clear</x-td.button>
                                    <x-td.button type="submit" name="decision" value="not_cleared" size="sm" :disabled="$check->recorded_by_id === auth()->id() && $fourEyes">Not cleared</x-td.button>
                                </form>
                                @if ($check->recorded_by_id === auth()->id() && $fourEyes)<p class="m-0 text-sm ops-muted">You recorded this one, so someone else reviews it.</p>@endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-td.panel>

        <x-td.panel title="Approved tutors without a current screening" flush>
            @if ($unscreened->isEmpty())
                <x-td.state kind="success" title="{{ $tutors->isEmpty() ? 'No approved tutors yet' : 'Every approved tutor is screened' }}" />
            @else
                <ul class="ops-work">
                    @foreach ($unscreened as $t)
                        @php($tutorUser = $t['profile']->user)
                        <li>
                            <details class="w-full">
                                <summary class="cursor-pointer"><span class="ops-work__title">{{ $tutorUser?->name }}</span> <span class="ops-muted text-sm">· missing {{ collect($t['missing'])->map($label)->join(', ') }}</span></summary>
                                @if ($tutorUser)
                                    <form method="POST" action="{{ route('ops.safeguarding.screening.store', $tutorUser) }}" enctype="multipart/form-data" class="ops-act mt-4">
                                        @csrf
                                        <x-td.field label="Check" for="kind-{{ $tutorUser->id }}"><x-td.select name="kind" id="kind-{{ $tutorUser->id }}" :options="collect($kinds)->mapWithKeys(fn ($k) => [$k->value => $k->label()])->all()" /></x-td.field>
                                        <x-td.field label="Checked on" for="checked-{{ $tutorUser->id }}"><x-td.input name="checked_on" id="checked-{{ $tutorUser->id }}" type="date" required /></x-td.field>
                                        <x-td.field label="Expires on" for="expires-{{ $tutorUser->id }}" optional><x-td.input name="expires_on" id="expires-{{ $tutorUser->id }}" type="date" /></x-td.field>
                                        <x-td.field label="Reference" for="ref-{{ $tutorUser->id }}" optional><x-td.input name="reference" id="ref-{{ $tutorUser->id }}" /></x-td.field>
                                        <x-td.field label="Evidence (PDF or image)" for="evidence-{{ $tutorUser->id }}" optional><input type="file" name="evidence" id="evidence-{{ $tutorUser->id }}" accept=".pdf,.png,.jpg,.jpeg" class="td-input"></x-td.field>
                                        <x-td.button type="submit" size="sm" variant="primary">Record</x-td.button>
                                    </form>
                                @endif
                            </details>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-td.panel>

        <x-td.panel title="Recent decisions" flush>
            @if ($recent->isEmpty())
                <x-td.state kind="empty" title="No screening decisions yet" />
            @else
                <x-td.table caption="Recent screening decisions" caption-hidden dense>
                    <thead><tr><th scope="col">Tutor</th><th scope="col">Check</th><th scope="col">Result</th><th scope="col"><span class="td-sr">Revoke</span></th></tr></thead>
                    <tbody>
                        @foreach ($recent as $check)
                            <tr>
                                <td>{{ $check->user?->name }}</td>
                                <td>{{ $label($check->kind) }}</td>
                                <td>@include('ops.partials.tag', match ($check->status) { 'cleared' => ['text' => 'Cleared'.($check->expires_on ? ' to '.$check->expires_on->format('j M Y') : ''), 'tone' => 'success'], 'revoked' => ['text' => 'Revoked', 'tone' => 'danger'], default => ['text' => 'Not cleared', 'tone' => 'danger'] })</td>
                                <td>
                                    @if ($check->status === 'cleared')
                                        <form method="POST" action="{{ route('ops.safeguarding.screening.revoke', $check) }}" class="ops-act">
                                            @csrf
                                            <x-td.field label="Reason" for="rev-{{ $check->id }}"><x-td.input name="reason" id="rev-{{ $check->id }}" required /></x-td.field>
                                            <x-td.button type="submit" size="sm" variant="danger">Revoke</x-td.button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-td.table>
            @endif
        </x-td.panel>
    </div>
</x-layouts::operations>
