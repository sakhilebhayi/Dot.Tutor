@php
    $layout = $interaction->actor_role === 'tutor' ? 'workspace' : 'learner';
    $back = $interaction->actor_role === 'tutor' ? route('ai.tutor.index') : route('ai.index');
@endphp
<x-dynamic-component :component="'layouts::'.$layout" title="Assistant answer">
    <x-td.page-head title="Assistant answer" :lede="'Request: '.str_replace('_', ' ', $interaction->task).', '.$interaction->created_at?->format('j M Y H:i')">
        <x-slot:actions><x-td.button :href="$back" icon="arrow-left">Back to the assistant</x-td.button></x-slot:actions>
    </x-td.page-head>

    <x-td.panel title="What you asked">
        <p style="max-width: var(--td-measure); white-space: pre-line">{{ $interaction->request_text }}</p>
    </x-td.panel>

    <x-td.panel title="Answer">
        @if ($own)
            @include('ai::partials.answer', ['data' => $data])
        @else
            @include('ai::partials.answer', ['data' => array_merge($data, ['id' => null])])
            <p class="text-td-ink-2">You are reading this as an operator. The read was recorded in the audit log.</p>
        @endif
        @if (($interaction->context['drafts_saved'] ?? null) !== null)
            <x-td.notice tone="info">{{ $interaction->context['drafts_saved'] }} draft question(s) saved for your review{{ ($interaction->context['drafts_skipped'] ?? 0) > 0 ? ', '.$interaction->context['drafts_skipped'].' left out because their answer key did not check out' : '' }}. <a href="{{ route('ai.tutor.index') }}#drafts">Review the drafts</a>.</x-td.notice>
        @endif
    </x-td.panel>
</x-dynamic-component>
