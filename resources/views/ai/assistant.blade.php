@php
    $taskLabels = [
        'explain' => 'Explain something to me',
        'simplify' => 'Make a text simpler',
        'practice' => 'Give me practice questions',
        'revision_summary' => 'Write a revision summary',
        'study_plan' => 'Make a study plan from my progress',
        'resource_help' => 'Help me with a library resource',
        'explain_mistake' => 'Explain a mistake in a quiz I submitted',
    ];
@endphp
<x-layouts::learner title="Study assistant" :step="$band === 'child' ? 'young' : 'standard'">
    <x-td.page-head title="Study assistant" lede="Ask for an explanation, practice or a plan. It helps you understand; it does not do marked work for you." />

    @unless ($configured)
        <x-td.state kind="empty" title="The AI assistant is not configured yet">
            The platform has not switched on an AI service, so nothing you type here is sent anywhere. Your tutors can help in the meantime.
            <x-slot:actions><x-td.button :href="route('marketplace.find')" icon="users">Find a tutor</x-td.button></x-slot:actions>
        </x-td.state>
    @else
        <div class="td-panels td-panels--2">
            <x-td.panel title="Ask">
                <form method="POST" action="{{ route('ai.ask') }}" class="grid gap-5">
                    @csrf
                    <x-td.field label="What would you like?" for="task">
                        <x-td.select name="task" :options="array_intersect_key($taskLabels, array_flip($tasks))" />
                    </x-td.field>
                    <x-td.field label="Your question or topic" for="text" hint="Do not type names, phone numbers or other personal details.">
                        <x-td.textarea name="text" rows="5" :hint="true" maxlength="{{ config('ai.max_input_chars') }}" />
                    </x-td.field>
                    <x-td.field label="Answer in" for="language">
                        <x-td.select name="language" :value="$language" :options="collect($languages)->mapWithKeys(fn ($l) => [$l['code'] => $l['name']])->all()" />
                    </x-td.field>
                    @if (count($modes) > 1)
                        <fieldset class="grid gap-2">
                            <legend class="td-label">How should it help?</legend>
                            <x-td.check type="radio" name="mode" value="understand" checked>Help me understand, step by step</x-td.check>
                            <x-td.check type="radio" name="mode" value="worked_example">Show a fully worked example of a similar problem</x-td.check>
                        </fieldset>
                    @endif
                    <details>
                        <summary>More options for some requests</summary>
                        <div class="grid gap-4 pt-3">
                            <x-td.field label="Number of practice questions" for="count" optional><x-td.input name="count" type="number" min="1" max="10" value="5" /></x-td.field>
                            <x-td.field label="Library resource number" for="resource_id" optional hint="The number at the end of the resource's address."><x-td.input name="resource_id" type="number" min="1" :hint="true" /></x-td.field>
                            <x-td.field label="Quiz attempt number" for="attempt_id" optional><x-td.input name="attempt_id" type="number" min="1" /></x-td.field>
                            <x-td.field label="Question number in that attempt" for="question_id" optional><x-td.input name="question_id" type="number" min="1" /></x-td.field>
                            <x-td.field label="Weeks for the study plan" for="weeks" optional><x-td.input name="weeks" type="number" min="1" max="12" value="4" /></x-td.field>
                            <x-td.field label="Hours a week" for="hours_per_week" optional><x-td.input name="hours_per_week" type="number" min="1" max="40" value="3" /></x-td.field>
                        </div>
                    </details>
                    <div><x-td.button type="submit" variant="primary">Ask the assistant</x-td.button></div>
                </form>
            </x-td.panel>

            <x-td.panel title="Before you ask">
                <div class="grid gap-3" style="max-width: var(--td-measure)">
                    <p>Answers are <strong>AI guidance</strong>: useful, but they can be wrong. Check anything important with a tutor or your teacher.</p>
                    <p>While you are in a timed quiz, and for questions from quizzes you still have to answer, the assistant will not help. After you submit, it can explain your mistakes.</p>
                    @unless ($age_known)
                        <p>Your age is not on your profile, so the assistant answers with the care it uses for young learners. <a href="{{ route('academic.profile.edit') }}">Add your birth month</a> to change that.</p>
                    @endunless
                    <x-td.stat-strip label="Today's use" :items="[
                        ['label' => 'Requests today', 'value' => $usage['requests_today'].' of '.$usage['requests_limit']],
                    ]" />
                    <p><a href="{{ route('ai.need.form') }}">Describe what you need</a> to find a tutor instead.</p>
                </div>
            </x-td.panel>
        </div>
    @endunless

    <x-td.panel title="Your recent questions" flush>
        @if ($recent->isEmpty())
            <x-td.state kind="empty" title="No questions yet">What you ask appears here, with the answer.</x-td.state>
        @else
            <x-td.table caption="Your recent questions" caption-hidden>
                <thead><tr><th>Request</th><th>Status</th><th>When</th></tr></thead>
                <tbody>
                    @foreach ($recent as $row)
                        <tr>
                            <td><a href="{{ route('ai.interactions.show', $row) }}">{{ $taskLabels[$row->task] ?? str_replace('_', ' ', $row->task) }}</a></td>
                            <td>{{ $row->answered() ? 'Answered' : 'Not answered' }}</td>
                            <td class="is-muted">{{ $row->created_at?->diffForHumans() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-td.table>
        @endif
    </x-td.panel>
</x-layouts::learner>
