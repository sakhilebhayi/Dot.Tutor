<x-app-layout>
    <x-slot name="header">{{ $assessment->title }}</x-slot>
    @include('learning.partials.styles')
    <div class="ln" style="max-width:48rem">
        <h1>{{ $assessment->title }}</h1>
        <p class="row muted">
            <span>{{ $assessment->kind->value === 'practice' ? 'Practice: does not count as an assessed result' : 'Assessed: marked against your tutor\'s answer key' }}</span>
            <span>Pass mark {{ $assessment->pass_percent }}%</span>
            @if ($assessment->time_limit_minutes) <span>Time limit {{ $assessment->time_limit_minutes }} minutes</span> @endif
            @if ($assessment->max_attempts) <span>{{ $assessment->max_attempts }} {{ \Illuminate\Support\Str::plural('attempt', $assessment->max_attempts) }} allowed</span> @endif
        </p>
        @include('learning.partials.flash')
        @if ($assessment->instructions)<p class="prose">{{ $assessment->instructions }}</p>@endif

        @if ($attempt === null)
            @if ($previous->isNotEmpty())
                <h2>Your earlier attempts</h2>
                <ul class="plain">@foreach ($previous as $earlier)<li><a href="{{ route('learning.attempts.show', $earlier->id) }}">{{ $earlier->submitted_at->toDayDateTimeString() }}</a>: {{ $earlier->score }}/{{ $earlier->max_score }} ({{ $earlier->percent }}%)</li>@endforeach</ul>
            @endif
            <form method="POST" action="{{ route('learning.assessments.attempts.store', $assessment) }}" style="margin-top:1.5rem">@csrf
                <button class="btn" type="submit">{{ $previous->isEmpty() ? 'Start' : 'Start a new attempt' }}</button>
                @if ($assessment->time_limit_minutes)<p class="hint">The timer starts when you press Start and runs on the server, even if you close this page.</p>@endif
            </form>
        @else
            @if ($data['deadline_at'])
                <div class="notice">Submit by <strong><time datetime="{{ $data['deadline_at'] }}">{{ $attempt->deadline_at->timezone(config('tutor.timezone'))->format('H:i') }}</time></strong> ({{ config('tutor.timezone') }}). Answers sent later are kept but marked late and cannot pass.</div>
            @endif
            <form method="POST" action="{{ route('learning.attempts.submit', $attempt) }}">
                @csrf
                @foreach ($data['questions'] as $index => $question)
                    <fieldset>
                        <legend>Question {{ $index + 1 }} <span class="muted">({{ $question['marks'] }} {{ \Illuminate\Support\Str::plural('mark', $question['marks']) }})</span></legend>
                        <p class="prose" style="margin-top:0">{{ $question['prompt'] }}</p>
                        @switch($question['type'])
                            @case('single_choice')
                                @foreach ($question['options'] as $option)
                                    <label style="font-weight:400"><input type="radio" name="answers[{{ $question['id'] }}]" value="{{ $option['key'] }}"> {{ $option['text'] }}</label>
                                @endforeach
                                @break
                            @case('multi_select')
                                <p class="hint">Choose every correct answer. Marks are given only for the exact set.</p>
                                @foreach ($question['options'] as $option)
                                    <label style="font-weight:400"><input type="checkbox" name="answers[{{ $question['id'] }}][]" value="{{ $option['key'] }}"> {{ $option['text'] }}</label>
                                @endforeach
                                @break
                            @case('numeric')
                                <label for="q{{ $question['id'] }}">Your answer (a number)</label>
                                <input id="q{{ $question['id'] }}" name="answers[{{ $question['id'] }}]" type="text" inputmode="decimal" autocomplete="off">
                                @break
                            @default
                                <label for="q{{ $question['id'] }}">Your answer</label>
                                <input id="q{{ $question['id'] }}" name="answers[{{ $question['id'] }}]" type="text" autocomplete="off">
                        @endswitch
                    </fieldset>
                @endforeach
                <button class="btn" type="submit">Submit for marking</button>
            </form>
        @endif
    </div>
</x-app-layout>
