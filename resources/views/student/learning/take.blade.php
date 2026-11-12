{{-- /learning/assessments/{id}/take (learning.assessments.take) (D-1120). From the learning controller: $assessment,
     $attempt (null before starting), $previous, $data (questions without the answer key, deadline). --}}
@php use App\Domain\Student\Support\Times; $practice = $assessment->kind->value === 'practice'; @endphp
<x-student.shell :title="$assessment->title">
    <x-td.page-head :title="$assessment->title" :lede="$practice ? 'Practice: your answers count toward topic progress, not as an assessed result.' : 'Assessed: marked against your tutor\'s answer key.'">
        <x-slot:actions><x-td.button variant="quiet" icon="arrow-left" :href="route('practice.index')">Practice</x-td.button></x-slot:actions>
    </x-td.page-head>

    <p class="st-inline st-dot st-small" style="margin:0">
        <span>Pass mark {{ $assessment->pass_percent }}%</span>
        @if ($assessment->time_limit_minutes)<span>Time limit {{ $assessment->time_limit_minutes }} minutes</span>@endif
        @if ($assessment->max_attempts)<span>{{ $assessment->max_attempts }} {{ \Illuminate\Support\Str::plural('attempt', $assessment->max_attempts) }} allowed</span>@endif
    </p>
    @if ($errors->any())<x-td.notice tone="danger" title="That did not go through">@foreach ($errors->all() as $m)<p style="margin:0">{{ $m }}</p>@endforeach</x-td.notice>@endif
    @if ($assessment->instructions)<p class="st-prose" style="margin:0;white-space:pre-line">{{ $assessment->instructions }}</p>@endif

    @if ($attempt === null)
        @if ($previous->isNotEmpty())
            <x-td.panel title="Your earlier attempts" flush>
                <ul class="st-list">
                    @foreach ($previous as $earlier)
                        <li class="st-row"><div><p class="st-row__title">{{ $earlier->score }} out of {{ $earlier->max_score }}</p><p class="st-row__meta">{{ Times::when($earlier->submitted_at) }}</p></div>
                            <div class="st-row__end"><x-td.button size="sm" variant="quiet" :href="route('learning.attempts.show', $earlier->id)">See answers</x-td.button></div></li>
                    @endforeach
                </ul>
            </x-td.panel>
        @endif
        <form method="POST" action="{{ route('learning.assessments.attempts.store', $assessment) }}" class="st-stack st-stack--tight">@csrf
            <div><x-td.button type="submit" variant="primary" size="lg">{{ $previous->isEmpty() ? 'Start' : 'Start a new attempt' }}</x-td.button></div>
            @if ($assessment->time_limit_minutes)<p class="st-small" style="margin:0">The timer starts when you press Start and runs on the server, even if you close this page.</p>@endif
        </form>
    @else
        @if ($data['deadline_at'])
            <x-td.notice tone="warning" title="Hand in by {{ Times::clock($attempt->deadline_at) }}">Answers sent later are kept but marked late and cannot pass. {{ Times::zoneNote() }}</x-td.notice>
        @endif
        <form method="POST" action="{{ route('learning.attempts.submit', $attempt) }}" class="st-stack">
            @csrf
            @foreach ($data['questions'] as $index => $question)
                <x-td.panel :title="'Question '.($index + 1)" :heading-level="2">
                    <fieldset class="st-form">
                        <legend class="st-prose" style="font-size:var(--td-text-md);font-weight:400">{{ $question['prompt'] }} <span class="st-small">({{ $question['marks'] }} {{ \Illuminate\Support\Str::plural('mark', $question['marks']) }})</span></legend>
                        @switch($question['type'])
                            @case('single_choice')
                                <div class="st-choices">
                                    @foreach ($question['options'] as $option)
                                        <label class="st-choice"><input type="radio" name="answers[{{ $question['id'] }}]" value="{{ $option['key'] }}"><span class="st-choice__title" style="font-weight:400">{{ $option['text'] }}</span></label>
                                    @endforeach
                                </div>
                                @break
                            @case('multi_select')
                                <p class="st-small" style="margin:0">Choose every correct answer. Marks are given only for the exact set.</p>
                                <div class="st-choices">
                                    @foreach ($question['options'] as $option)
                                        <label class="st-choice"><input type="checkbox" name="answers[{{ $question['id'] }}][]" value="{{ $option['key'] }}"><span class="st-choice__title" style="font-weight:400">{{ $option['text'] }}</span></label>
                                    @endforeach
                                </div>
                                @break
                            @case('numeric')
                                <x-td.field label="Your answer (a number)" for="q{{ $question['id'] }}"><x-td.input name="answers[{{ $question['id'] }}]" id="q{{ $question['id'] }}" inputmode="decimal" autocomplete="off" /></x-td.field>
                                @break
                            @default
                                <x-td.field label="Your answer" for="q{{ $question['id'] }}"><x-td.input name="answers[{{ $question['id'] }}]" id="q{{ $question['id'] }}" autocomplete="off" /></x-td.field>
                        @endswitch
                    </fieldset>
                </x-td.panel>
            @endforeach
            <div><x-td.button type="submit" variant="primary" size="lg">Submit for marking</x-td.button></div>
        </form>
    @endif
</x-student.shell>
