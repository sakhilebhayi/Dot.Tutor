{{-- /learning/attempts/{id} (learning.attempts.show): a marked attempt (D-1120). From the learning controller: $result. --}}
<x-student.shell :title="$result['assessment']['title']">
    <x-td.page-head :title="$result['assessment']['title'].': '.$result['score'].' out of '.$result['max_score']" :lede="$result['label']">
        <x-slot:actions>
            {{-- One way back (R-47 polish D-2018): the assessment, which links on to practice. --}}
            <x-td.button variant="quiet" icon="arrow-left" :href="route('learning.assessments.take', $result['assessment']['id'])">Back to the assessment</x-td.button>
        </x-slot:actions>
    </x-td.page-head>

    <div class="st-inline">
        <span @class(['td-badge', 'td-badge--verified' => $result['passed'], 'td-badge--review' => ! $result['passed']])><x-td.icon :name="$result['passed'] ? 'circle-check' : 'circle-dashed'" />{{ $result['passed'] ? 'Passed' : 'Not passed yet' }} ({{ $result['percent'] }}%, pass mark {{ $result['pass_percent'] }}%)</span>
        @if ($result['late'])<span class="td-badge td-badge--lapsed"><x-td.icon name="clock" />Handed in after the time limit</span>@endif
    </div>

    <ol class="st-stack" style="margin:0;padding:0;list-style:none">
        @foreach ($result['questions'] as $question)
            <li>
                <x-td.panel :title="'Question '.$loop->iteration">
                    <div class="st-stack st-stack--tight">
                        <p style="margin:0">{{ $question['prompt'] }}</p>
                        <p class="st-inline" style="margin:0">
                            <span @class(['td-badge', 'td-badge--verified' => $question['correct'], 'td-badge--review' => ! $question['correct']])><x-td.icon :name="$question['correct'] ? 'check' : 'minus'" />{{ $question['correct'] ? 'Correct' : 'Not correct' }}, {{ $question['marks_awarded'] }} of {{ $question['marks'] }}</span>
                        </p>
                        <p class="st-small" style="margin:0">Your answer: {{ is_array($question['your_response']) ? implode(', ', $question['your_response']) : ($question['your_response'] ?? 'no answer') }}</p>
                        @isset($question['answer_key'])
                            <p style="margin:0"><span class="st-small">Expected:</span>
                                @if (isset($question['answer_key']['key'])) {{ collect($question['options'])->firstWhere('key', $question['answer_key']['key'])['text'] ?? $question['answer_key']['key'] }}
                                @elseif (isset($question['answer_key']['keys'])) {{ collect($question['options'])->whereIn('key', $question['answer_key']['keys'])->pluck('text')->implode(', ') }}
                                @elseif (isset($question['answer_key']['value'])) {{ $question['answer_key']['value'] }}{{ $question['answer_key']['tolerance'] > 0 ? ' (± '.$question['answer_key']['tolerance'].')' : '' }}
                                @else {{ implode(' or ', $question['answer_key']['accepted'] ?? []) }} @endif
                            </p>
                            @if ($question['explanation'])<p class="st-muted" style="margin:0">{{ $question['explanation'] }}</p>@endif
                        @endisset
                    </div>
                </x-td.panel>
            </li>
        @endforeach
    </ol>
    @if (Route::has('ai.index'))
        <p class="st-small" style="margin:0">Not sure why an answer was wrong? The <a class="td-link" href="{{ route('ai.index') }}">study assistant</a> can explain a mistake in a quiz you handed in, when it is switched on. Its explanation is guidance, not a mark.</p>
    @endif
</x-student.shell>
