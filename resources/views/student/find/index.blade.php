{{-- /find-a-tutor (marketplace.find): the need form and the explained match list (D-1107).
     From the marketplace controller: $subjects, $result, $helpTypes. From FindComposer: $form, $levelOptions,
     $languageOptions, $understood, $needNotes, $foundSubject, $rows. --}}
@php
    use App\Domain\Student\Support\Times;
    $young = ($ctx['step'] ?? 'standard') === 'young';
    $count = $result ? count($result['results']) : 0;
    $weekdayNames = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
@endphp
<x-student.shell :title="$young ? 'Get help' : 'Find a tutor'">
    <x-td.page-head :title="$young ? 'Get help' : 'Find a tutor'"
        lede="Say what you need. We rank tutors on how well they fit and tell you why. Nobody pays to be placed higher, and you book one lesson at a time." />

    <form method="POST" action="{{ route('learn.need') }}" class="st-slip" role="search" aria-labelledby="find-need-label">
        @csrf
        <label class="st-slip__label" id="find-need-label" for="need">{{ $young ? 'What do you need help with?' : 'In your own words' }}</label>
        <div class="st-slip__row">
            <x-td.input name="need" :value="$form['need']" maxlength="500" autocomplete="off" placeholder="Grade 11 Maths in isiZulu, after school, under R250" />
            <x-td.button type="submit" variant="primary" icon="search">Read it</x-td.button>
        </div>
        @error('need')<p class="td-error"><x-td.icon name="circle-alert" />{{ $message }}</p>@enderror
    </form>

    @if ($understood !== [])
        <x-td.notice tone="info" title="What we understood">
            <dl class="st-facts" style="margin-top:var(--td-space-2)">
                @foreach ($understood as $item)
                    <dt>{{ $item['label'] }}</dt>
                    <dd>{{ $item['value'] }} <span class="st-small">from {{ $item['from'] }}</span></dd>
                @endforeach
            </dl>
            <p style="margin:var(--td-space-2) 0 0">Change anything in the form if we got it wrong.</p>
        </x-td.notice>
    @endif
    @if ($foundSubject === false)
        <x-td.notice tone="warning" title="Which subject?">We could not tell the subject from your words. Choose it in the form and we will show tutors.</x-td.notice>
    @endif
    @foreach ($needNotes as $note)
        <x-td.notice tone="warning">{{ $note }}</x-td.notice>
    @endforeach
    @if ($errors->any() && ! $errors->has('need'))
        <x-td.notice tone="danger" title="Check the form">{{ $errors->first() }}</x-td.notice>
    @endif

    <div @class(["st-cols", "st-cols--filters-first" => ! $result || $count === 0])>
        <section class="st-stack" aria-labelledby="results-title" aria-live="polite">
            @if ($result)
                <div class="st-stack st-stack--tight">
                    <h2 class="st-h2" id="results-title">{{ $count === 0 ? 'No tutor fits all of that yet' : $count.' '.\Illuminate\Support\Str::plural('tutor', $count).' '.($count === 1 ? 'fits' : 'fit') }}</h2>
                    <p class="st-small" style="margin:0">{{ $result['considered'] }} {{ \Illuminate\Support\Str::plural('tutor', $result['considered']) }} {{ $result['considered'] === 1 ? 'teaches' : 'teach' }} this subject; {{ $result['excluded'] }} did not meet something you asked for (format, area, language, budget, level or kind of help). Best fit first; ties are shuffled fairly.</p>
                    @foreach ($result['notes'] as $note)<p class="st-small" style="margin:0">{{ $note }}</p>@endforeach
                </div>

                @if ($count === 0)
                    <x-td.state kind="empty" title="Try asking for a little less">
                        <ul style="margin:0;padding-left:1.1rem;list-style:disc;text-align:left">
                            @if ($form['budget_rand'] !== '')<li>Remove the budget, or raise it.</li>@endif
                            @if ($form['format'] !== 'either')<li>Allow online and in-person lessons.</li>@endif
                            @if ($form['languages'] !== [])<li>Add another language you understand, or allow bilingual lessons.</li>@endif
                            @if ($form['weekdays'] !== [] || $form['from_time'] !== '')<li>Widen the days or times.</li>@endif
                            @if ($form['help_type'] !== '')<li>Choose "Any" kind of help.</li>@endif
                            <li>Check the study library and courses for this subject.</li>
                        </ul>
                        <x-slot:actions><x-td.button :href="route('learning.library.index')" icon="book-marked">Study library</x-td.button></x-slot:actions>
                    </x-td.state>
                @else
                    <div class="td-panels">
                        @foreach ($rows as $row)
                            @php $tutor = $row['tutor']; @endphp
                            <article class="st-record" aria-labelledby="tutor-{{ $tutor['id'] }}">
                                <div class="st-record__head">
                                    <x-td.avatar :name="$tutor['name']" :src="$tutor['has_photo'] ? route('marketplace.tutors.photo', $tutor['id']) : null" decorative />
                                    <div class="st-stack st-stack--tight">
                                        <h3 class="st-record__name" id="tutor-{{ $tutor['id'] }}"><a href="{{ route('marketplace.tutors.show', $tutor['id']) }}">{{ $tutor['name'] }}</a></h3>
                                        @if ($tutor['headline'])<p class="st-muted" style="margin:0">{{ $tutor['headline'] }}</p>@endif
                                        <div class="st-inline">@include('student.partials.verification', ['verification' => $tutor['verification']])</div>
                                    </div>
                                    @if ($row['price'])
                                        <p class="st-record__price" style="margin:0"><x-td.money :minor="$row['price']['per_hour_minor']" :currency="$row['price']['currency']" per="hour" /></p>
                                    @endif
                                </div>
                                <div class="st-record__body">
                                    <x-td.match-reasons :reasons="$row['lines']" label="Why {{ $tutor['name'] }} fits" />
                                    <div class="st-stack">
                                        @if ($row['teaching_languages'] !== [])
                                            <div class="st-chipset" aria-label="Teaches in">
                                                @foreach ($row['teaching_languages'] as $lang)
                                                    <x-td.language-chip :code="$lang['code']" :name="$lang['name']" :endonym="$lang['endonym']" role="teaches" :verified="$lang['verified']" :match="$lang['match']" />
                                                @endforeach
                                            </div>
                                        @endif
                                        @if ($row['formats'] !== [])<p class="st-small" style="margin:0">{{ implode(' · ', $row['formats']) }}</p>@endif
                                        @if ($row['next_slots'] !== [])
                                            <div>
                                                <p class="st-small" style="margin:0 0 var(--td-space-1)">Next open {{ \Illuminate\Support\Str::plural('time', count($row['next_slots'])) }}</p>
                                                <p style="margin:0" class="st-inline st-dot">
                                                    @foreach ($row['next_slots'] as $slot)
                                                        <span>{{ is_string($slot) ? Times::when($slot) : Times::when($slot['starts_at'] ?? '') }}</span>
                                                    @endforeach
                                                </p>
                                            </div>
                                        @endif
                                        <div class="st-inline">
                                            <x-td.button variant="primary" :href="route('marketplace.tutors.show', $tutor['id'])" icon-end="arrow-right">See profile and book</x-td.button>
                                        </div>
                                    </div>
                                </div>
                                <details class="st-young-hide">
                                    <summary class="td-link" style="cursor:pointer">How this was ranked</summary>
                                    <x-td.table caption="Ranking for {{ $tutor['name'] }}" caption-hidden>
                                        <thead><tr><th scope="col">What we checked</th><th scope="col" class="is-num">Counts for</th><th scope="col" class="is-num">Points</th></tr></thead>
                                        <tbody>
                                            @foreach ($row['terms'] as $term)
                                                <tr><td>{{ ['scope' => 'Subject and level', 'curriculum' => 'Curriculum', 'language' => 'Teaching language', 'availability' => 'Open times', 'budget' => 'Budget', 'help_type' => 'Kind of help', 'verification' => 'Verification', 'reviews' => 'Reviews', 'new_tutor' => 'New tutor lift'][$term['term']] ?? $term['term'] }}</td>
                                                    <td class="is-num">{{ rtrim(rtrim(number_format($term['weight'], 1), '0'), '.') }}</td>
                                                    <td class="is-num">{{ rtrim(rtrim(number_format($term['points'], 2), '0'), '.') ?: '0' }}</td></tr>
                                            @endforeach
                                            <tr><th scope="row">Total</th><td class="is-num">100</td><td class="is-num">{{ rtrim(rtrim(number_format($row['score'], 2), '0'), '.') ?: '0' }}</td></tr>
                                        </tbody>
                                    </x-td.table>
                                </details>
                            </article>
                        @endforeach
                    </div>
                    @if (collect($rows)->contains(fn ($r) => $r['next_slots'] !== []))<p class="st-small" style="margin:0">{{ Times::zoneNote() }}</p>@endif
                @endif
            @else
                <h2 class="st-h2" id="results-title">{{ $young ? 'Pick a subject to see tutors' : 'Choose a subject to see tutors' }}</h2>
                <x-td.state kind="empty" title="Tutors appear here, best fit first">
                    Each one comes with the reasons they fit, and anything that does not, so you can decide. Verification is shown exactly as Dot.Tutor checked it.
                </x-td.state>
            @endif
        </section>

        <aside aria-labelledby="filters-title">
            <details class="td-panel" @if (! $result || $count === 0) open @endif style="padding:0">
                <summary class="td-panel__head" style="cursor:pointer"><h2 class="td-panel__title" id="filters-title">{{ $result ? 'Change what you asked for' : 'What you need' }}</h2></summary>
                <form method="GET" action="{{ route('match.search') }}" class="st-form td-panel__body">
                    <x-td.field label="Subject" for="subject_id">
                        <x-td.select name="subject_id" required :value="$form['subject_id']" placeholder="Choose a subject"
                            :options="$subjects->mapWithKeys(fn ($s) => [$s->id => $s->name])->all()" />
                    </x-td.field>
                    <x-td.field label="Grade or level" for="level_id" optional>
                        <x-td.select name="level_id" :value="$form['level_id']" placeholder="Any level" :options="$levelOptions" />
                    </x-td.field>
                    <fieldset>
                        <legend>Taught in <span class="td-label__optional">(most preferred first)</span></legend>
                        <div class="st-chipset">
                            @foreach ($languageOptions as $language)
                                <label><input type="checkbox" name="languages[]" value="{{ $language->code }}" @checked(in_array($language->code, $form['languages'], true))> <span lang="{{ $language->code }}">{{ $language->name_native }}</span></label>
                            @endforeach
                        </div>
                        <p class="td-hint" style="margin:var(--td-space-2) 0 0">Tutors are matched on the languages they teach in, not on the language of this website.</p>
                    </fieldset>
                    <x-td.check name="bilingual_ok" value="1" :checked="$form['bilingual_ok']">Bilingual lessons are fine (for example, isiZulu with English terms)</x-td.check>
                    <x-td.field label="Kind of help" for="help_type">
                        <x-td.select name="help_type" :value="$form['help_type']" placeholder="Any" :options="collect($helpTypes)->mapWithKeys(fn ($t) => [$t->value => $t->label()])->all()" />
                    </x-td.field>
                    <div class="st-form__pair">
                        <x-td.field label="How" for="format">
                            <x-td.select name="format" :value="$form['format']" :options="['either' => 'Online or in person', 'online' => 'Online', 'in_person' => 'In person']" />
                        </x-td.field>
                        <x-td.field label="Area" for="area" optional hint="For in-person lessons">
                            <x-td.input name="area" :value="$form['area']" maxlength="120" autocomplete="address-level2" :hint="true" />
                        </x-td.field>
                    </div>
                    <x-td.field label="Budget per hour, in rand" for="budget_rand" optional>
                        <x-td.input name="budget_rand" :value="$form['budget_rand']" inputmode="numeric" pattern="[0-9]*" maxlength="6" />
                    </x-td.field>
                    <fieldset>
                        <legend>Days <span class="td-label__optional">(optional)</span></legend>
                        <div class="st-chipset">
                            @foreach ($weekdayNames as $n => $label)
                                <label><input type="checkbox" name="weekdays[]" value="{{ $n }}" @checked(in_array($n, $form['weekdays'], true))> {{ $label }}</label>
                            @endforeach
                        </div>
                    </fieldset>
                    <div class="st-form__pair">
                        <x-td.field label="From" for="from_time" optional><x-td.input type="time" name="from_time" :value="$form['from_time']" /></x-td.field>
                        <x-td.field label="Until" for="to_time" optional><x-td.input type="time" name="to_time" :value="$form['to_time']" /></x-td.field>
                    </div>
                    <input type="hidden" name="need" value="{{ $form['need'] }}">
                    <div class="st-form__actions"><x-td.button type="submit" variant="primary" icon="search">Show tutors</x-td.button></div>
                </form>
            </details>
        </aside>
    </div>
</x-student.shell>
