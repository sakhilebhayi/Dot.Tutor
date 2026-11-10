<x-app-layout>
    <x-slot name="header">{{ $result['assessment']['title'] }}</x-slot>
    @include('learning.partials.styles')
    <div class="ln" style="max-width:48rem">
        <h1>{{ $result['assessment']['title'] }}: {{ $result['score'] }} out of {{ $result['max_score'] }}</h1>
        <p class="row">
            <span class="tag {{ $result['passed'] ? 'ok' : 'warn' }}">{{ $result['passed'] ? 'Passed' : 'Not passed' }} ({{ $result['percent'] }}%, pass mark {{ $result['pass_percent'] }}%)</span>
            @if ($result['late']) <span class="tag warn">Submitted after the time limit</span> @endif
        </p>
        <p class="muted">{{ $result['label'] }}</p>
        <ol>
            @foreach ($result['questions'] as $question)
                <li style="margin-bottom:1rem">
                    <p style="margin:0">{{ $question['prompt'] }}</p>
                    <p class="row" style="margin:.3rem 0">
                        <span class="tag {{ $question['correct'] ? 'ok' : 'warn' }}">{{ $question['correct'] ? 'Correct' : 'Not correct' }}, {{ $question['marks_awarded'] }}/{{ $question['marks'] }}</span>
                        <span class="muted">Your answer: {{ is_array($question['your_response']) ? implode(', ', $question['your_response']) : ($question['your_response'] ?? 'no answer') }}</span>
                    </p>
                    @isset($question['answer_key'])
                        <p class="muted" style="margin:0">Expected:
                            @if (isset($question['answer_key']['key'])) {{ collect($question['options'])->firstWhere('key', $question['answer_key']['key'])['text'] ?? $question['answer_key']['key'] }}
                            @elseif (isset($question['answer_key']['keys'])) {{ collect($question['options'])->whereIn('key', $question['answer_key']['keys'])->pluck('text')->implode(', ') }}
                            @elseif (isset($question['answer_key']['value'])) {{ $question['answer_key']['value'] }}{{ $question['answer_key']['tolerance'] > 0 ? ' (± '.$question['answer_key']['tolerance'].')' : '' }}
                            @else {{ implode(' or ', $question['answer_key']['accepted'] ?? []) }} @endif
                        </p>
                        @if ($question['explanation'])<p class="muted" style="margin:0">{{ $question['explanation'] }}</p>@endif
                    @endisset
                </li>
            @endforeach
        </ol>
        <p><a href="{{ route('learning.assessments.take', $result['assessment']['id']) }}">Back to the assessment</a></p>
    </div>
</x-app-layout>
