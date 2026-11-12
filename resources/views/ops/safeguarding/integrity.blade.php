@php
    $tz = \App\Domain\Student\Support\Times::zone();
    $names = $people($cases->getCollection()->pluck('subject_user_id')->filter()->unique()->all());
@endphp
<x-layouts::operations title="Academic integrity">
    @include('ops.safeguarding._head', ['title' => 'Academic integrity', 'lede' => 'Plagiarism, exam misconduct and requests to do assessed work, impersonation, fake reviews, misleading qualifications, copyright and fraudulent sales. Reports of cheating requests open a case here automatically. No automated detection exists.'])

    <div class="grid gap-6">
        <x-td.panel flush>
            @if ($cases->isEmpty())
                <x-td.state kind="success" title="No integrity cases">Cases opened by reports or by an operator appear here.</x-td.state>
            @else
                <ul class="ops-work">
                    @foreach ($cases as $c)
                        <li>
                            <div class="grid gap-2">
                                <p class="ops-work__title">{{ \App\Domain\Safety\Enums\IntegrityKind::from($c->kind instanceof \BackedEnum ? $c->kind->value : $c->kind)->label() }}
                                    <span class="ops-muted" style="font-weight:400">· {{ $c->subject_user_id ? ($names[$c->subject_user_id] ?? 'Deleted account') : 'nobody named' }} · opened {{ $c->created_at->timezone($tz)->format('j M Y') }}</span></p>
                                <p class="m-0" style="max-width: var(--td-measure)">{{ $c->summary }}</p>
                                @if ($c->status !== 'open')
                                    <p class="ops-work__meta">@include('ops.partials.tag', ['text' => $c->status === 'upheld' ? 'Upheld' : 'Not upheld', 'tone' => $c->status === 'upheld' ? 'warning' : 'neutral']) {{ $c->finding }} · sanction: {{ str_replace('_', ' ', $c->sanction) }}</p>
                                @elseif ($canAct)
                                    <form method="POST" action="{{ route('ops.safeguarding.integrity.decide', $c) }}" class="ops-act">
                                        @csrf
                                        <x-td.field label="Finding" for="finding-{{ $c->id }}"><x-td.input name="finding" id="finding-{{ $c->id }}" required /></x-td.field>
                                        <x-td.field label="Sanction" for="sanction-{{ $c->id }}"><x-td.select name="sanction" id="sanction-{{ $c->id }}" :options="collect($sanctions)->mapWithKeys(fn ($s) => [$s => ucfirst(str_replace('_', ' ', $s))])->all()" value="none" /></x-td.field>
                                        <x-td.field label="Days" for="days-{{ $c->id }}" optional class="w-28"><x-td.input name="days" id="days-{{ $c->id }}" type="number" min="1" /></x-td.field>
                                        <x-td.button type="submit" name="upheld" value="1" size="sm">Uphold</x-td.button>
                                        <x-td.button type="submit" name="upheld" value="0" size="sm" variant="quiet">Not upheld</x-td.button>
                                    </form>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
                @if ($cases->hasPages())<div class="px-5 py-3"><x-td.pagination :paginator="$cases" label="Case pages" /></div>@endif
            @endif
        </x-td.panel>

        @if ($canAct)
            <x-td.panel title="Open a case">
                <form method="POST" action="{{ route('ops.safeguarding.integrity.store') }}" class="ops-act">
                    @csrf
                    <x-td.field label="Kind" for="case-kind"><x-td.select name="kind" id="case-kind" :options="collect($kinds)->mapWithKeys(fn ($k) => [$k->value => $k->label()])->all()" /></x-td.field>
                    <x-td.field label="What is alleged" for="case-summary"><x-td.input name="summary" id="case-summary" required /></x-td.field>
                    <x-td.field label="Person's email" for="case-subject" optional><x-td.input name="subject_email" id="case-subject" type="email" /></x-td.field>
                    <x-td.button type="submit" variant="primary">Open case</x-td.button>
                </form>
            </x-td.panel>
        @endif
    </div>
</x-layouts::operations>
