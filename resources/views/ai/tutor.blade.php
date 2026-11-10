@php
    $taskLabels = [
        'lesson_outline' => 'Lesson outline',
        'quiz_drafts' => 'Draft quiz questions into a bank',
        'revision_exercises' => 'Revision exercises',
        'course_description' => 'Course description',
        'struggle_summary' => 'Where my students struggle',
        'resource_suggestions' => 'Suggest library resources',
    ];
@endphp
<x-layouts::workspace title="Teaching assistant">
    <x-td.page-head title="Teaching assistant" lede="Drafts for you to check: outlines, questions, exercises and descriptions. Nothing reaches a learner until you use it." />

    @unless ($configured)
        <x-td.notice tone="info" title="The AI assistant is not configured yet">The platform has not switched on an AI service, so nothing is sent anywhere. The student summary below is computed without AI and works now.</x-td.notice>
    @endunless
    @unless ($is_tutor)
        <x-td.notice tone="warning">The teaching assistant is for people with a tutor profile. <a href="{{ route('tutor.profile.edit') }}">Create your tutor profile</a>.</x-td.notice>
    @endunless

    <div class="td-panels td-panels--2">
        <x-td.panel title="Ask">
            @if ($configured && $is_tutor)
                <form method="POST" action="{{ route('ai.tutor.ask') }}" class="grid gap-5">
                    @csrf
                    <x-td.field label="What do you need?" for="task">
                        <x-td.select name="task" :options="$taskLabels" />
                    </x-td.field>
                    <x-td.field label="Topic and notes" for="text" hint="For example: Grade 10 CAPS, functions and graphs, learners mix up the sign of q.">
                        <x-td.textarea name="text" rows="4" :hint="true" maxlength="{{ config('ai.max_input_chars') }}" />
                    </x-td.field>
                    <x-td.field label="Question bank (for quiz drafts)" for="bank_id" optional>
                        <x-td.select name="bank_id" placeholder="Choose a bank" :options="$banks->pluck('title', 'id')->all()" />
                    </x-td.field>
                    <x-td.field label="Course (for a course description)" for="course_id" optional>
                        <x-td.select name="course_id" placeholder="Choose a course" :options="$courses->pluck('title', 'id')->all()" />
                    </x-td.field>
                    <x-td.field label="How many questions or exercises" for="count" optional><x-td.input name="count" type="number" min="1" max="10" value="5" /></x-td.field>
                    <x-td.field label="Write in" for="language">
                        <x-td.select name="language" :options="collect($languages)->mapWithKeys(fn ($l) => [$l['code'] => $l['name']])->all()" />
                    </x-td.field>
                    <div><x-td.button type="submit" variant="primary">Ask the assistant</x-td.button></div>
                </form>
            @else
                <x-td.state kind="empty" title="Not available">{{ $configured ? 'A tutor profile is needed.' : 'Switch on an AI service to use these tools.' }}</x-td.state>
            @endif
        </x-td.panel>

        <x-td.panel title="Where your students struggle">
            <p class="text-td-ink-2">Computed from your learners' marked answers on Dot.Tutor. Not AI-generated, and not an official result.</p>
            @if ($struggles['topics'] === [])
                <x-td.state kind="empty" title="No marked work yet">When your learners answer quiz questions, the topics that need attention appear here.</x-td.state>
            @else
                <x-td.table caption="Topics by learners needing attention" dense>
                    <thead><tr><th>Topic</th><th class="is-num">Learners</th><th class="is-num">Need attention</th><th class="is-num">Declining</th></tr></thead>
                    <tbody>
                        @foreach (array_slice($struggles['topics'], 0, 12) as $topic)
                            <tr><td>{{ $topic['topic'] }}</td><td class="is-num">{{ $topic['learners'] }}</td><td class="is-num">{{ $topic['needs_attention'] }}</td><td class="is-num">{{ $topic['declining'] }}</td></tr>
                        @endforeach
                    </tbody>
                </x-td.table>
            @endif
        </x-td.panel>
    </div>

    <x-td.panel title="Question drafts to review" id="drafts">
        @if ($drafts->isEmpty())
            <x-td.state kind="empty" title="No drafts waiting">Drafts the assistant writes into your question banks wait here until you accept or discard them.</x-td.state>
        @else
            <ul class="grid gap-6">
                @foreach ($drafts as $draft)
                    <li class="grid gap-2" style="max-width: var(--td-measure)">
                        <p class="text-td-ink-2">AI draft for {{ $draft->bank?->title }}, {{ str_replace('_', ' ', $draft->type) }}. Check the question and the answer key.</p>
                        <p><strong>{{ $draft->prompt }}</strong></p>
                        @if ($draft->options)
                            <ul class="pl-5 list-disc">@foreach ($draft->options as $o)<li>{{ $o['key'] }}) {{ $o['text'] }}</li>@endforeach</ul>
                        @endif
                        <p>Answer key: {{ collect($draft->answer)->map(fn ($v) => is_array($v) ? implode(', ', $v) : $v)->implode('; ') }}</p>
                        @if ($draft->explanation)<p class="text-td-ink-2">{{ $draft->explanation }}</p>@endif
                        <div class="flex flex-wrap gap-3">
                            <form method="POST" action="{{ route('ai.drafts.accept', $draft) }}">@csrf<x-td.button type="submit" variant="primary" icon="check">Add to my bank</x-td.button></form>
                            <form method="POST" action="{{ route('ai.drafts.discard', $draft) }}">@csrf<x-td.button type="submit" variant="quiet" icon="trash-2">Discard</x-td.button></form>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-td.panel>

    <x-td.panel title="Recent requests" flush>
        @if ($recent->isEmpty())
            <x-td.state kind="empty" title="No requests yet">Your requests and the answers appear here.</x-td.state>
        @else
            <x-td.table caption="Recent requests" caption-hidden>
                <thead><tr><th>Request</th><th>Status</th><th>When</th></tr></thead>
                <tbody>
                    @foreach ($recent as $row)
                        <tr><td><a href="{{ route('ai.interactions.show', $row) }}">{{ $taskLabels[$row->task] ?? $row->task }}</a></td><td>{{ $row->answered() ? 'Answered' : 'Not answered' }}</td><td class="is-muted">{{ $row->created_at?->diffForHumans() }}</td></tr>
                    @endforeach
                </tbody>
            </x-td.table>
        @endif
    </x-td.panel>
</x-layouts::workspace>
