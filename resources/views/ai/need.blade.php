@php
    $r = $result['result'] ?? null;
    $aiAssisted = ($result['source'] ?? null) === 'ai_assisted';
@endphp
<x-layouts::learner title="Describe what you need">
    <x-td.page-head title="Describe what you need" lede="Say it in your own words. We read it, show you what we understood, and you confirm before we look for tutors." />

    <x-td.panel title="Your words">
        <form method="POST" action="{{ route('ai.need.describe') }}" class="grid gap-4">
            @csrf
            <x-td.field label="What do you need help with?" for="text" hint="For example: Grade 11 Maths in isiZulu after school. Please leave out names and contact details.">
                <x-td.input name="text" :value="$text" :hint="true" maxlength="500" required />
            </x-td.field>
            @if ($result && $result['unsure'] && ! $aiAssisted && $result['ai_available'])
                <x-td.check name="use_ai" value="1">We could not read all of it. Let the AI assistant reword it (you still confirm)</x-td.check>
            @endif
            <div><x-td.button type="submit" variant="primary">Read my description</x-td.button></div>
        </form>
    </x-td.panel>

    @if ($r)
        <x-td.panel title="What we understood">
            @if ($aiAssisted)
                <x-td.notice tone="info" title="AI guidance">The assistant reworded your description as "{{ $result['normalised'] }}". The levels and subjects below come from the platform's catalogue. Check them before you continue.</x-td.notice>
            @endif
            @if (! empty($result['ai_message']))<x-td.notice tone="warning">{{ $result['ai_message'] }}</x-td.notice>@endif

            @if ($r['subjects'] === [])
                <x-td.state kind="empty" title="No subject found">Try naming the subject, for example Mathematics or Accounting.</x-td.state>
            @else
                <form method="{{ $aiAssisted ? 'POST' : 'GET' }}" action="{{ $aiAssisted ? $result['confirm_url'] : route('marketplace.find') }}" class="grid gap-4">
                    @if ($aiAssisted) @csrf @endif
                    <fieldset class="grid gap-2"><legend class="td-label">Subject</legend>
                        @foreach ($r['subjects'] as $i => $s)<x-td.check type="radio" name="subject_id" :value="$s['id']" :checked="$i === 0">{{ $s['name'] }}</x-td.check>@endforeach
                    </fieldset>
                    @if ($r['levels'] !== [])
                        <fieldset class="grid gap-2"><legend class="td-label">Level</legend>
                            @foreach ($r['levels'] as $i => $l)<x-td.check type="radio" name="level_id" :value="$l['id']" :checked="$i === 0">{{ $l['name'] }}, {{ $l['curriculum'] }}</x-td.check>@endforeach
                        </fieldset>
                    @else
                        <p class="text-td-ink-2">No grade or level found. You can still look for tutors by subject.</p>
                    @endif
                    @if ($r['teaching_languages'] !== [])
                        <fieldset class="grid gap-2"><legend class="td-label">Taught in</legend>
                            @foreach ($r['teaching_languages'] as $lang)<x-td.check name="languages[]" :id="'lang-'.$lang['code']" :value="$lang['code']" :checked="$lang['certainty'] === 'stated'">{{ $lang['name'] }}{{ $lang['certainty'] === 'possible' ? ' (you may have meant the subject)' : '' }}</x-td.check>@endforeach
                        </fieldset>
                    @endif
                    <div><x-td.button type="submit" variant="primary">Yes, find tutors for this</x-td.button></div>
                </form>
            @endif
        </x-td.panel>
    @endif
</x-layouts::learner>
