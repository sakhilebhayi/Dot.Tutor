<x-layouts::workspace :title="$assessment->title" step="dense">
    <x-td.page-head :title="$assessment->title" :lede="($assessment->course ? 'Quiz in '.$assessment->course->title.'. ' : '').$attached->count().' '.\Illuminate\Support\Str::plural('question', $attached->count()).', pass at '.$assessment->pass_percent.'%'.($assessment->time_limit_minutes ? ', '.$assessment->time_limit_minutes.' minutes' : '').'.'">
        <x-slot:actions>
            @if ($assessment->course)<x-td.button href="{{ route('teach.courses.show', [$assessment->course_id, 'tab' => 'quizzes']) }}" icon="arrow-left">Course</x-td.button>@endif
            <x-td.button href="{{ route('teach.materials.assessments') }}">Banks and assessments</x-td.button>
        </x-slot:actions>
    </x-td.page-head>

    <div class="td-panels td-panels--2">
        <x-td.panel title="Questions in it">
            @forelse ($attached as $i => $q)
                <p class="m-0 border-t border-td-line py-2 first:border-t-0">{{ $i + 1 }}. {{ \Illuminate\Support\Str::limit($q->prompt, 160) }}</p>
            @empty
                <p class="mt-0 text-td-ink-2">No questions yet. Tick questions from your banks to add them.</p>
            @endforelse
            @if ($available->isNotEmpty())
                <form method="POST" action="{{ route('teach.materials.assessments.attach', $assessment->id) }}" class="mt-4 grid gap-2 border-t border-td-line pt-4">
                    @csrf
                    <p class="m-0 font-bold">Add from your banks</p>
                    @error('question_ids')<p class="td-error">{{ $message }}</p>@enderror
                    @foreach ($available->groupBy(fn ($q) => $q->bank?->title) as $bankTitle => $qs)
                        <fieldset class="grid gap-1"><legend class="text-sm text-td-ink-2">{{ $bankTitle }}</legend>
                            @foreach ($qs as $q)<x-td.check name="question_ids[]" id="q-{{ $q->id }}" :value="$q->id">{{ \Illuminate\Support\Str::limit($q->prompt, 120) }}</x-td.check>@endforeach
                        </fieldset>
                    @endforeach
                    <div><x-td.button type="submit" variant="primary">Add ticked questions</x-td.button></div>
                </form>
            @else
                <p class="mb-0 mt-4 text-sm text-td-ink-2"><a class="td-link" href="{{ route('teach.materials.assessments') }}">Write questions in a question bank</a> to add them here.</p>
            @endif
        </x-td.panel>

        <x-td.panel title="{{ $assessment->kind->value === 'course_quiz' ? 'Learners in the course take it in a quiz lesson' : 'Assign to a student' }}">
            @if ($assessment->kind->value === 'course_quiz')
                <p class="m-0 text-td-ink-2">Add a Quiz lesson to the course and choose this quiz. Results appear below.</p>
            @elseif ($students->isEmpty())
                <x-td.state kind="empty" title="No students yet">You can assign work only to learners you teach: a confirmed or completed lesson, or an active enrolment in your course.</x-td.state>
            @else
                <form method="POST" action="{{ route('teach.materials.assessments.assign', $assessment->id) }}" class="grid gap-3">
                    @csrf
                    <x-td.field label="Student" for="learner_id"><x-td.select name="learner_id" :options="$students->mapWithKeys(fn ($s) => [$s['id'] => $s['name']])->all()" /></x-td.field>
                    <x-td.field label="Due by" for="due_date" optional><x-td.input name="due_date" type="date" min="{{ now($tz)->addDay()->format('Y-m-d') }}" /></x-td.field>
                    <div><x-td.button type="submit" variant="primary">Assign</x-td.button></div>
                </form>
                @if ($assignments->isNotEmpty())
                    <p class="mb-1 mt-4 font-bold">Assigned to</p>
                    <ul class="m-0 pl-5">@foreach ($assignments as $as)<li>{{ \App\Domain\Teach\Support\TutorContext::learnerName($as->learner) }}{{ $as->due_at ? ', due '.$as->due_at->copy()->timezone($tz)->format('j M') : '' }}</li>@endforeach</ul>
                @endif
            @endif
        </x-td.panel>
    </div>

    <x-td.panel title="Submitted attempts" flush class="mt-6">
        @if ($attempts->isEmpty())
            <div class="p-5"><p class="m-0 text-td-ink-2">No submitted attempts yet. Your own test attempts do not count.</p></div>
        @else
            <x-td.table caption="Submitted attempts" caption-hidden dense>
                <thead><tr><th scope="col">Learner</th><th scope="col">Submitted</th><th scope="col" class="is-num">Score</th><th scope="col">Result</th></tr></thead>
                <tbody>
                    @foreach ($attempts as $at)
                        <tr><th scope="row">{{ \App\Domain\Teach\Support\TutorContext::learnerName($at->user) }}</th><td class="tabular-nums">{{ $at->submitted_at->copy()->timezone($tz)->format('j M Y, H:i') }}</td><td class="is-num">{{ $at->score }} of {{ $at->max_score }} ({{ $at->percent }}%)</td><td>{{ $at->passed ? 'Passed' : 'Not passed' }}{{ $at->late ? ', late' : '' }}</td></tr>
                    @endforeach
                </tbody>
            </x-td.table>
        @endif
    </x-td.panel>
</x-layouts::workspace>
