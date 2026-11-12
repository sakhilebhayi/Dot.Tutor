@php
    $typeWords = ['single_choice' => 'One correct option', 'multi_select' => 'Several correct options (all needed)', 'numeric' => 'A number', 'short_text' => 'A short written answer'];
    $answerText = function ($q) {
        $a = $q->answer ?? [];
        return match ($q->type?->value ?? $q->type) {
            'single_choice' => 'Correct: '.($a['key'] ?? '?'),
            'multi_select' => 'Correct: '.implode(', ', $a['keys'] ?? []),
            'numeric' => 'Answer '.($a['value'] ?? '?').(($a['tolerance'] ?? 0) > 0 ? ' within '.$a['tolerance'] : ''),
            default => 'Accepted: '.implode('; ', $a['accepted'] ?? []),
        };
    };
@endphp
<x-layouts::workspace :title="$bank->title" step="dense">
    <x-td.page-head :title="$bank->title" :lede="'Topic: '.$bank->topic.($bank->strand ? ', '.$bank->strand : '').'. Answers here are visible only to you.'">
        <x-slot:actions><x-td.button href="{{ route('teach.materials.assessments') }}" icon="arrow-left">Banks and assessments</x-td.button></x-slot:actions>
    </x-td.page-head>
    <div class="td-panels td-panels--2">
        <x-td.panel title="Questions">
            @forelse ($questions as $i => $q)
                <article class="grid gap-1 border-t border-td-line py-3 first:border-t-0 {{ $q->retired_at ? 'opacity-70' : '' }}">
                    <p class="m-0 font-bold">{{ $i + 1 }}. {{ $q->prompt }}</p>
                    @if (is_array($q->options))
                        <ul class="m-0 pl-5 text-sm">@foreach ($q->options as $o)<li>{{ $o['key'] }}. {{ $o['text'] }}</li>@endforeach</ul>
                    @endif
                    <p class="m-0 text-sm text-td-ink-2">{{ $typeWords[$q->type?->value ?? $q->type] ?? '' }}. {{ $answerText($q) }}. {{ $q->marks }} {{ \Illuminate\Support\Str::plural('mark', $q->marks) }}.{{ $q->retired_at ? ' Retired.' : '' }}</p>
                    @unless ($q->retired_at)
                        <form method="POST" action="{{ route('teach.materials.questions.retire', $q->id) }}">@csrf<x-td.button type="submit" variant="quiet" size="sm">Retire<span class="td-sr"> question {{ $i + 1 }}</span></x-td.button></form>
                    @endunless
                </article>
            @empty
                <x-td.state kind="empty" title="No questions yet">Add your first question. To change one later, retire it and add a new one, so earlier marks stay true.</x-td.state>
            @endforelse
        </x-td.panel>
        <x-td.panel title="Add a question">
            <form method="POST" action="{{ route('teach.materials.questions.store', $bank->id) }}" class="grid gap-4">
                @csrf
                <x-td.field label="Kind of question" for="type"><x-td.select name="type" :options="$typeWords" /></x-td.field>
                <x-td.field label="Question" for="prompt"><x-td.textarea name="prompt" rows="3" required /></x-td.field>
                <fieldset class="grid gap-2">
                    <legend class="td-label">Options (for option questions)</legend>
                    @foreach (['A', 'B', 'C', 'D', 'E'] as $k)
                        <div class="grid grid-cols-[2rem_1fr] items-center gap-2"><span class="font-bold">{{ $k }}</span><x-td.input name="option_{{ $k }}" id="option-{{ $k }}" maxlength="500" aria-label="Option {{ $k }}" /></div>
                    @endforeach
                </fieldset>
                <x-td.field label="Correct option (one correct)" for="correct" optional><x-td.select name="correct" :options="['A' => 'A', 'B' => 'B', 'C' => 'C', 'D' => 'D', 'E' => 'E']" placeholder="Choose" /></x-td.field>
                <fieldset class="grid gap-1"><legend class="td-label">Correct options (several correct)</legend>
                    <div class="flex flex-wrap gap-4">@foreach (['A', 'B', 'C', 'D', 'E'] as $k)<x-td.check name="correct_many[]" id="many-{{ $k }}" :value="$k">{{ $k }}</x-td.check>@endforeach</div>
                </fieldset>
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-td.field label="Number answer" for="numeric_value" optional><x-td.input name="numeric_value" inputmode="decimal" /></x-td.field>
                    <x-td.field label="Allowed difference" for="numeric_tolerance" optional><x-td.input name="numeric_tolerance" inputmode="decimal" placeholder="0" /></x-td.field>
                </div>
                <x-td.field label="Accepted written answers" for="accepted" optional hint="One per line. Capitals, spacing and a final full stop are ignored."><x-td.textarea name="accepted" rows="3" :hint="true" /></x-td.field>
                <x-td.field label="Explanation shown after submitting" for="explanation" optional><x-td.textarea name="explanation" rows="2" /></x-td.field>
                <x-td.field label="Marks" for="marks"><x-td.input name="marks" type="number" min="1" max="100" value="1" /></x-td.field>
                @error('answer')<x-td.notice tone="danger">{{ $message }}</x-td.notice>@enderror
                <div><x-td.button type="submit" variant="primary">Add question</x-td.button></div>
            </form>
        </x-td.panel>
    </div>
</x-layouts::workspace>
