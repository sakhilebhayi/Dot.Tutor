{{-- One assistant answer: the AI guidance label, the text (Markdown, HTML stripped), practice questions with answers
     behind a disclosure, notes, and the two ways out: ask a tutor, report it. $data = AiInteraction::present(). --}}
@php
    $markdown = fn (?string $text) => \Illuminate\Support\Str::markdown((string) $text, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
    $questions = $data['data']['questions'] ?? null;
    $reportCategories = collect(\App\Domain\Ai\Http\Controllers\AssistantController::REPORT_CATEGORIES)
        ->mapWithKeys(fn ($c) => [$c => \App\Domain\Safety\Enums\ReportCategory::from($c)->label()])->all();
@endphp
<div class="grid gap-4">
    <x-td.notice tone="info" :title="$data['label']">{{ $data['disclaimer'] }}@if ($data['model']) Written by {{ $data['model'] }}.@endif</x-td.notice>

    @if ($data['safety_note'])
        <x-td.notice tone="warning" title="If you need help now">{{ $data['safety_note'] }}</x-td.notice>
    @endif

    @if ($data['message'])
        <x-td.notice :tone="$data['status'] === 'incomplete' ? 'warning' : 'danger'">{{ $data['message'] }}</x-td.notice>
    @endif

    @if (is_array($questions))
        <ol class="grid gap-4 list-decimal pl-5" style="max-width: var(--td-measure)">
            @foreach ($questions as $q)
                <li class="grid gap-2">
                    <div class="[&_p]:mb-3 [&_ul]:list-disc [&_ul]:pl-5 [&_ol]:list-decimal [&_ol]:pl-5 [&_li]:mb-1 [&_h1]:font-semibold [&_h2]:font-semibold [&_h3]:font-semibold [&_code]:font-mono">{!! $markdown($q['question'] ?? '') !!}</div>
                    <details><summary>Hint</summary><div class="[&_p]:mb-3 [&_ul]:list-disc [&_ul]:pl-5 [&_ol]:list-decimal [&_ol]:pl-5 [&_li]:mb-1 [&_h1]:font-semibold [&_h2]:font-semibold [&_h3]:font-semibold [&_code]:font-mono">{!! $markdown($q['hint'] ?? '') !!}</div></details>
                    <details><summary>Answer and explanation</summary><div class="[&_p]:mb-3 [&_ul]:list-disc [&_ul]:pl-5 [&_ol]:list-decimal [&_ol]:pl-5 [&_li]:mb-1 [&_h1]:font-semibold [&_h2]:font-semibold [&_h3]:font-semibold [&_code]:font-mono">{!! $markdown(($q['answer'] ?? '')."\n\n".($q['explanation'] ?? '')) !!}</div></details>
                </li>
            @endforeach
        </ol>
    @elseif (is_array($data['data']['suggestions'] ?? null))
        @if ($data['data']['suggestions'] === [])
            <x-td.state kind="empty" title="No library resource fits this request">The assistant looked only at real resources in the library and found none that fit.</x-td.state>
        @else
            <ul class="grid gap-3" style="max-width: var(--td-measure)">
                @foreach ($data['data']['suggestions'] as $s)
                    <li><a href="{{ $s['url'] }}">{{ $s['title'] }}</a>: {{ $s['why'] }}</li>
                @endforeach
            </ul>
        @endif
    @elseif ($data['text'])
        <div class="[&_p]:mb-3 [&_ul]:list-disc [&_ul]:pl-5 [&_ol]:list-decimal [&_ol]:pl-5 [&_li]:mb-1 [&_h1]:font-semibold [&_h2]:font-semibold [&_h3]:font-semibold [&_code]:font-mono" lang="{{ $data['language'] }}" style="max-width: var(--td-measure)">{!! $markdown($data['text']) !!}</div>
    @endif

    @if ($data['language_note'])
        <p class="text-td-ink-2">{{ $data['language_note'] }}</p>
    @endif

    @if ($data['id'])
        <div class="flex flex-wrap items-start gap-3">
            <x-td.button :href="$data['ask_a_tutor_url']" icon="users">Ask a tutor instead</x-td.button>
            <details class="grid gap-3">
                <summary class="td-btn td-btn--quiet" style="list-style:none;cursor:pointer"><x-td.icon name="flag" /> Report this answer</summary>
                <form method="POST" action="{{ route('ai.interactions.report', $data['id']) }}" class="grid gap-4 pt-3" style="max-width: 36rem">
                    @csrf
                    <x-td.field label="What is wrong with it?" for="category-{{ $data['id'] }}">
                        <x-td.select name="category" id="category-{{ $data['id'] }}" :options="$reportCategories" />
                    </x-td.field>
                    <x-td.field label="Anything the safety team should know" for="details-{{ $data['id'] }}" optional>
                        <x-td.textarea name="details" id="details-{{ $data['id'] }}" rows="3" />
                    </x-td.field>
                    <div><x-td.button type="submit" variant="primary">Send the report to the safety team</x-td.button></div>
                </form>
            </details>
        </div>
    @endif
</div>
