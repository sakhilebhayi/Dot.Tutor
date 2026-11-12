@php $t = $view['translation']; @endphp
<x-student.shell title="Original and translation">
    <x-td.page-head title="Original and translation" lede="The original always stays. A machine translation is labelled until a person who reads the language reviews it." />

    <form method="GET" action="{{ route('ai.translations.show', [$type, $id, $field]) }}" class="flex flex-wrap items-end gap-3">
        <x-td.field label="Show the translation in" for="locale">
            <x-td.select name="locale" :value="$locale" placeholder="Choose a language" :options="collect($languages)->mapWithKeys(fn ($l) => [$l['code'] => $l['name']])->all()" />
        </x-td.field>
        <x-td.button type="submit">Show</x-td.button>
    </form>

    <div class="td-panels td-panels--2">
        <x-td.panel :title="'Original ('.$view['original']['locale'].')'">
            <div lang="{{ $view['original']['locale'] }}" style="max-width: var(--td-measure); white-space: pre-line">{{ $view['original']['text'] }}</div>
        </x-td.panel>

        <x-td.panel :title="$locale !== '' ? 'Translation ('.$locale.')' : 'Translation'">
            @if ($t)
                <div class="grid gap-3">
                    <x-td.notice :tone="$t['review_status'] === 'approved' ? 'success' : 'info'" :title="$t['provenance_label']">
                        {{ $t['review_label'] }}.@if ($t['machine_model']) Machine translation by {{ $t['machine_model'] }}.@endif @if ($t['translator']) Translated by {{ $t['translator'] }}.@endif
                    </x-td.notice>
                    @if ($t['out_of_date'])<x-td.notice tone="warning">The original changed after this translation was made.</x-td.notice>@endif
                    @if ($t['notation_missing'])<x-td.notice tone="warning" title="Check these against the original">{{ implode(', ', $t['notation_missing']) }}</x-td.notice>@endif
                    <div lang="{{ $t['locale'] }}" style="max-width: var(--td-measure); white-space: pre-line">{{ $t['text'] }}</div>
                    @if ($view['may_review'] && $view['translation_id'])
                        <div class="flex flex-wrap gap-3">
                            <form method="POST" action="{{ route('ai.translations.review', $view['translation_id']) }}">@csrf<input type="hidden" name="decision" value="approved"><x-td.button type="submit" icon="badge-check">I read it: mark reviewed</x-td.button></form>
                            <form method="POST" action="{{ route('ai.translations.review', $view['translation_id']) }}">@csrf<input type="hidden" name="decision" value="rejected"><x-td.button type="submit" variant="quiet">Reject it</x-td.button></form>
                        </div>
                    @endif
                </div>
            @elseif ($locale === '')
                <x-td.state kind="empty" title="Choose a language">Pick a language above to see a translation next to the original.</x-td.state>
            @else
                <x-td.state kind="empty" title="No translation in this language yet">
                    @if ($configured)
                        A machine translation can be made now. It will be labelled as not reviewed, and formulas and numbers are checked against the original.
                    @else
                        The AI service is not configured, so no machine translation can be made.
                    @endif
                    @if ($configured)
                        <x-slot:actions>
                            <form method="POST" action="{{ route('ai.translations.store') }}">@csrf
                                <input type="hidden" name="type" value="{{ $type }}"><input type="hidden" name="id" value="{{ $id }}">
                                <input type="hidden" name="field" value="{{ $field }}"><input type="hidden" name="locale" value="{{ $locale }}">
                                <x-td.button type="submit" variant="primary" icon="languages">Translate by machine</x-td.button>
                            </form>
                        </x-slot:actions>
                    @endif
                </x-td.state>
            @endif
        </x-td.panel>
    </div>
</x-student.shell>
