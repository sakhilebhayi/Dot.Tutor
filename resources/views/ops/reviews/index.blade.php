@php $tz = \App\Domain\Student\Support\Times::zone(); @endphp
<x-layouts::operations title="Reviews and disputes">
    <x-td.page-head title="Reviews and disputes" lede="Disputed lessons and tutor reviews held for a moderator. Only reviews from learners who took the lesson are ever published." />
    @include('ops.reviews._tabs')

    <div class="grid gap-6">
        @if ($disputes !== null)
            <x-td.panel title="Disputed lessons" flush>
                @if ($disputes->isEmpty())
                    <x-td.state kind="success" title="No disputed lessons">A learner or tutor can dispute a lesson up to seven days after it ends.</x-td.state>
                @else
                    <ul class="ops-work">
                        @foreach ($disputes as $b)
                            <li>
                                <div class="grid gap-2">
                                    <p class="ops-work__title"><a href="{{ route('ops.reviews.booking', $b) }}">{{ $b->subject?->name ?? 'Lesson' }} · {{ $b->starts_at?->timezone($tz)->format('j M Y H:i') }}</a></p>
                                    <p class="ops-work__meta">Tutor {{ $b->tutorProfile?->user?->name ?? 'unknown' }} · learner {{ $b->student?->name ?? 'unknown' }}{{ $b->decision_reason ? ' · '.$b->decision_reason : '' }}</p>
                                    @if ($canResolve)
                                        @include('ops.reviews._resolve', ['booking' => $b])
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-td.panel>
        @endif

        @if ($held !== null)
            <x-td.panel title="Held tutor reviews" flush>
                @if ($held->isEmpty())
                    <x-td.state kind="success" title="No held reviews">Reviews are held when they contain contact details or after three different people report them.</x-td.state>
                @else
                    <ul class="ops-work">
                        @foreach ($held as $r)
                            <li>
                                <div class="grid gap-2">
                                    <p class="ops-work__meta">{{ $r->rating }} of 5 for {{ $r->session?->tutorProfile?->user?->name ?? 'a tutor' }} · @include('ops.partials.tag', ['text' => $r->held_reason ?: 'Held', 'tone' => 'warning']) · {{ (int) ($reportCounts[$r->id] ?? 0) }} reports</p>
                                    <p class="m-0 whitespace-pre-line" style="max-width: var(--td-measure)">{{ $r->review ?: 'No written review.' }}</p>
                                    <form method="POST" action="{{ route('ops.reviews.moderate', $r) }}" class="ops-act">
                                        @csrf
                                        <x-td.field label="Reason" for="rv-{{ $r->id }}"><x-td.input name="reason" id="rv-{{ $r->id }}" required minlength="3" /></x-td.field>
                                        <x-td.button type="submit" name="to" value="published" size="sm">Publish</x-td.button>
                                        <x-td.button type="submit" name="to" value="removed" size="sm" variant="danger">Remove</x-td.button>
                                    </form>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-td.panel>
        @endif
    </div>
</x-layouts::operations>
