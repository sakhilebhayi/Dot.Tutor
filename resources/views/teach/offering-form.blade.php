@php
    $descriptions = [
        'one_to_one' => 'A lesson with one learner at a time they request.',
        'group' => 'You set a time; several learners take seats and each pays for one.',
        'consultation' => 'A short call to plan, unblock or answer a few questions.',
        'homework_help' => 'Help working through set homework, without doing it for the learner.',
        'exam_prep' => 'Focused preparation for a test or exam.',
        'revision' => 'Going back over a topic or a term before assessments.',
        'package' => 'Several lessons bought together, booked one by one before they expire.',
        'recurring_weekly' => 'The same time every week for a set number of weeks; each lesson is paid on its own.',
    ];
    $editing = $offering !== null;
    $title = $editing ? 'Edit offering' : 'New offering';
    $priceValue = old('price', $editing ? number_format($offering->price_minor / 100, 2, '.', '') : (($profile->hourly_rate_minor ?? 0) > 0 ? number_format($profile->hourly_rate_minor / 100, 2, '.', '') : ''));
    $selectedSubjects = collect(old('subject_ids', $editing ? $offering->subjects->pluck('id')->all() : (count($subjects) === 1 ? [$subjects[0]['value']] : [])))->map(fn ($v) => (int) $v)->all();
    $selectedLevels = collect(old('level_ids', $editing ? (array) $offering->level_ids : []))->map(fn ($v) => (int) $v)->all();
    $durations = collect(range($limits['min'], $limits['max'], 15))->mapWithKeys(fn ($m) => [$m => $m.' minutes'])->all();
    $money = fn (int $minor) => \App\Domain\Teach\Support\TutorContext::money($minor, $split['currency']);
@endphp
<x-layouts::workspace :title="$title">
    <x-td.page-head :title="$title" :lede="$type ? $type->label().'. '.$descriptions[$type->value] : 'Choose what you are selling. You can create as many offerings as you like.'">
        <x-slot:actions><x-td.button href="{{ route('teach.offerings') }}" icon="arrow-left">Offerings</x-td.button></x-slot:actions>
    </x-td.page-head>

    @if (! $type)
        <x-td.panel title="What kind of offering?">
            <ul class="m-0 grid list-none gap-px bg-td-line p-0 md:grid-cols-2">
                @foreach ($types as $t)
                    <li class="bg-td-surface">
                        <a href="{{ route('teach.offerings.create', ['type' => $t->value]) }}" class="block p-4 text-td-ink no-underline hover:bg-td-sunken focus-visible:bg-td-sunken">
                            <span class="block font-bold text-td-primary">{{ $t->label() }}</span>
                            <span class="block text-sm text-td-ink-2">{{ $descriptions[$t->value] }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </x-td.panel>
    @else
        @if ($subjects === [])
            <x-td.notice tone="warning" title="Add what you teach first">An offering covers subjects from your teaching scope. <a class="td-link" href="{{ route('teach.profile', ['step' => 'scope']) }}">Add subjects</a></x-td.notice>
        @endif
        <form method="POST" action="{{ $editing ? route('teach.offerings.update', $offering) : route('teach.offerings.store') }}" class="td-panels td-panels--2" data-teach-price-form data-preview-url="{{ route('teach.offerings.preview') }}">
            @csrf
            @if ($editing) @method('PUT') @else <input type="hidden" name="type" value="{{ $type->value }}"> @endif
            <x-td.panel title="Details">
                <div class="grid gap-5">
                    <x-td.field label="Title learners see" for="title" hint="For example: Grade 12 Maths exam preparation, 90 minutes.">
                        <x-td.input name="title" :value="$offering?->title" maxlength="160" required :hint="true" />
                    </x-td.field>
                    <x-td.field label="What is included" for="description" optional>
                        <x-td.textarea name="description" :value="$offering?->description" rows="4" />
                    </x-td.field>
                    <fieldset class="grid gap-2">
                        <legend class="td-label">Subjects</legend>
                        @error('subject_ids')<p class="td-error" id="subject_ids-error">{{ $message }}</p>@enderror
                        <div class="grid gap-2 sm:grid-cols-2">
                            @foreach ($subjects as $s)
                                <x-td.check name="subject_ids[]" id="subject-{{ $s['value'] }}" :value="$s['value']" :checked="in_array($s['value'], $selectedSubjects, true)">{{ $s['label'] }}</x-td.check>
                            @endforeach
                        </div>
                    </fieldset>
                    @if ($levels !== [])
                        <x-td.field label="Levels" for="level_ids" optional hint="Leave empty to cover your whole teaching scope. Hold Ctrl or Command to choose several.">
                            <select name="level_ids[]" id="level_ids" multiple size="6" class="td-select" aria-describedby="level_ids-hint">
                                @foreach (collect($levels)->groupBy('group') as $group => $items)
                                    <optgroup label="{{ $group }}">
                                        @foreach ($items as $l)<option value="{{ $l['value'] }}" @selected(in_array($l['value'], $selectedLevels, true))>{{ $l['label'] }}</option>@endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                        </x-td.field>
                    @endif
                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-td.field label="Length of each lesson" for="duration_minutes">
                            <x-td.select name="duration_minutes" :options="$durations" :value="$offering?->duration_minutes ?? 60" />
                        </x-td.field>
                        <x-td.field label="Where" for="format">
                            <x-td.select name="format" :options="collect($formats)->mapWithKeys(fn ($f) => [$f->value => ['online' => 'Online', 'in_person' => 'In person', 'either' => 'Online or in person'][$f->value] ?? $f->value])->all()" :value="$offering?->format ?? 'online'" />
                        </x-td.field>
                    </div>
                    <x-td.field label="Area for in-person lessons" for="area" optional hint="A suburb or town, never a street address.">
                        <x-td.input name="area" :value="$offering?->area ?? $profile->in_person_area" maxlength="120" :hint="true" />
                    </x-td.field>
                    @if ($type->value === 'group')
                        <x-td.field label="Seats" for="capacity" hint="2 to {{ $limits['capacity'] }} learners.">
                            <x-td.input name="capacity" type="number" min="2" max="{{ $limits['capacity'] }}" :value="$offering?->capacity ?? 6" required :hint="true" />
                        </x-td.field>
                    @endif
                    @if ($type->value === 'package')
                        <div class="grid gap-5 sm:grid-cols-2">
                            <x-td.field label="Lessons in the package" for="package_lessons"><x-td.input name="package_lessons" type="number" min="2" max="{{ $limits['package'] }}" :value="$offering?->package_lessons ?? 5" required /></x-td.field>
                            <x-td.field label="Valid for (days)" for="package_valid_days" hint="Unused lessons expire after this."><x-td.input name="package_valid_days" type="number" min="7" max="730" :value="$offering?->package_valid_days ?? 90" :hint="true" /></x-td.field>
                        </div>
                    @endif
                    @if ($type->value === 'recurring_weekly')
                        <x-td.field label="Number of weeks" for="recurring_weeks" hint="2 to {{ $limits['weeks'] }} weeks. Weeks that clash with your calendar are skipped when a learner books.">
                            <x-td.input name="recurring_weeks" type="number" min="2" max="{{ $limits['weeks'] }}" :value="$offering?->recurring_weeks ?? 8" required :hint="true" />
                        </x-td.field>
                    @endif
                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-td.field label="Free cancellation until (hours before)" for="cancellation_notice_hours">
                            <x-td.input name="cancellation_notice_hours" type="number" min="0" max="168" :value="$offering?->cancellation_notice_hours ?? 24" />
                        </x-td.field>
                        <x-td.field label="Refund for a later cancellation (%)" for="late_cancellation_refund_percent" hint="When you cancel, the learner always gets 100%.">
                            <x-td.input name="late_cancellation_refund_percent" type="number" min="0" max="100" :value="$offering?->late_cancellation_refund_percent ?? 50" :hint="true" />
                        </x-td.field>
                    </div>
                </div>
            </x-td.panel>

            <x-td.panel title="Price and what you keep">
                <div class="grid gap-5">
                    <x-td.field :label="($priceLabels[$type->value] ?? 'Price per lesson').' ('.$split['currency'].')'" for="price" hint="0 makes it free.">
                        <x-td.input name="price" inputmode="decimal" :value="$priceValue" required :hint="true" data-teach-price />
                    </x-td.field>
                    <dl class="m-0 grid gap-2" data-teach-split aria-live="polite">
                        <div class="flex justify-between gap-3"><dt>Learner pays</dt><dd class="m-0 tabular-nums" data-split="price">{{ $money($split['price_minor']) }}</dd></div>
                        <div class="flex justify-between gap-3"><dt>Platform commission (<span data-split="percent">{{ rtrim(rtrim(number_format($split['commission_percent'], 2), '0'), '.') }}</span>%)</dt><dd class="m-0 tabular-nums" data-split="commission">−{{ $money($split['commission_minor']) }}</dd></div>
                        <div data-split="charges" class="grid gap-2">
                            @foreach ($split['charges'] as $c)
                                <div class="flex justify-between gap-3"><dt>{{ $c['label'] }}</dt><dd class="m-0 tabular-nums">−{{ $money($c['minor']) }}</dd></div>
                            @endforeach
                        </div>
                        <div class="flex justify-between gap-3 border-t border-td-line pt-2 text-lg font-bold"><dt>You keep</dt><dd class="m-0 tabular-nums" data-split="net">{{ $money($split['net_minor']) }}</dd></div>
                    </dl>
                    <p class="m-0 text-sm text-td-ink-2" data-split="status">Worked out with the commission rule in force now{{ $split['rule_version'] ? ' (version '.$split['rule_version'].')' : '' }}. Each sale keeps the rule it was priced with.</p>
                    <p class="m-0 text-sm text-td-ink-2">Payment processing fees are {{ (float) config('money.charges.pass_gateway_fee_percent', 0) > 0 ? 'passed on, as shown' : 'paid by the platform' }}. A discount code you fund lowers the price commission is taken from. Earnings are pending until the lesson is delivered, plus {{ (int) config('money.earnings.hold_hours_after_lesson') }} hours for complaints, then available for payout.</p>
                    @if ($editing)
                        <input type="hidden" name="is_active" value="{{ $offering->is_active ? 1 : 0 }}">
                    @endif
                    <div class="flex flex-wrap gap-3">
                        <x-td.button type="submit" variant="primary">{{ $editing ? 'Save changes' : 'Save offering' }}</x-td.button>
                        <x-td.button href="{{ route('teach.offerings') }}" variant="quiet">Cancel</x-td.button>
                    </div>
                </div>
            </x-td.panel>
        </form>
    @endif
</x-layouts::workspace>
