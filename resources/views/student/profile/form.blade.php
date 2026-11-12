{{-- The learner profile form, shared by onboarding (/learn/welcome) and the learning profile (/learn/profile).
     Data comes from the academic LearnerProfileController::edit (same variables). $mode: onboarding | profile.
     Works without script: choosing a level submits the small form above (script does it on change). --}}
@php
    $isOnboarding = $mode === 'onboarding';
    $selfRoute = $isOnboarding ? route('learn.onboarding') : route('academic.profile.edit');
    $chosen = collect(old('subjects', $subjectIds))->map(fn ($id) => (int) $id)->all();
    [$languageOfferings, $otherOfferings] = $offerings->partition(fn ($o) => $o->subject->category === 'languages');
    $bilingual = old('bilingual_ok', $profile?->bilingual_ok === null ? null : ($profile->bilingual_ok ? '1' : '0'));
    $levelOptions = $curricula->flatMap(fn ($c) => $c->levels->map(fn ($l) => ['value' => $l->id, 'label' => $l->name, 'group' => $c->label()]))->all();
@endphp

<div class="st-stack">
    {{-- Plain words in, form filled out. --}}
    <form method="GET" action="{{ $selfRoute }}" class="st-slip" role="search">
        <label class="st-slip__label" for="need">{{ __('academic.onboarding.describe_label') }}</label>
        <div class="st-slip__row">
            <x-td.input name="need" :value="$need" maxlength="500" placeholder="{{ __('academic.onboarding.describe_hint') }}" autocomplete="off" />
            <x-td.button type="submit">{{ __('academic.onboarding.describe_button') }}</x-td.button>
        </div>
        @if ($parsed)
            <p class="td-hint" role="status" data-testid="need-result" style="margin:0">
                @if ($parsed['levels'] || $parsed['subjects'])
                    {{ __('academic.onboarding.describe_result', ['text' => $need]) }}
                @else
                    {{ __('academic.onboarding.describe_nothing', ['text' => $need]) }}
                @endif
                @foreach ($parsed['notes'] as $note)
                    @if ($note['key'] === 'not_offered')
                        <br>{{ __('academic.onboarding.not_offered', ['subject' => $note['subject'], 'level' => $note['level']]) }}
                    @endif
                @endforeach
            </p>
        @endif
    </form>

    {{-- Country and level. Choosing reloads with that level's subjects. --}}
    <x-td.panel title="Where you are in your studies">
        <form method="GET" action="{{ $selfRoute }}" class="st-form" data-st-autosubmit>
            <div class="st-form__pair">
                <x-td.field :label="__('academic.onboarding.country')" for="country">
                    <x-td.select name="country" :options="$countries->mapWithKeys(fn ($c) => [$c->id => $c->name])->all()" :value="$countryId" />
                </x-td.field>
                <x-td.field :label="__('academic.onboarding.level')" for="level" :hint="__('academic.onboarding.curriculum_hint')">
                    <x-td.select name="level" :options="$levelOptions" :value="$level?->id" :placeholder="__('academic.onboarding.choose_level')" :hint="true" />
                </x-td.field>
            </div>
            <div class="st-form__actions" data-st-noscript-only>
                <x-td.button type="submit">{{ __('academic.onboarding.show_subjects') }}</x-td.button>
            </div>
        </form>
    </x-td.panel>

    @if ($level)
        <form method="POST" action="{{ $isOnboarding ? route('learn.onboarding.store') : route('academic.profile.update') }}" class="st-stack">
            @csrf
            @unless ($isOnboarding) @method('PUT') @endunless
            <input type="hidden" name="country_id" value="{{ $countryId }}">
            <input type="hidden" name="academic_level_id" value="{{ $level->id }}">

            <x-td.panel :title="__('academic.onboarding.subjects')">
                <div class="st-stack">
                    <p class="st-inline" style="margin:0">
                        <x-td.pill kind="level" :stage="\App\Domain\Student\Support\Stage::colour($level->stage?->code)">{{ $level->name }}</x-td.pill>
                        <span class="st-small">{{ __('academic.onboarding.curriculum') }}: {{ $level->curriculum->label() }}</span>
                    </p>
                    @error('academic_level_id')<p class="td-error"><x-td.icon name="circle-alert" />{{ $message }}</p>@enderror
                    @error('country_id')<p class="td-error"><x-td.icon name="circle-alert" />{{ $message }}</p>@enderror
                    <fieldset class="st-chipset" aria-describedby="subjects-hint">
                        <legend class="td-sr">{{ __('academic.onboarding.subjects') }}</legend>
                        @foreach ($otherOfferings as $offering)
                            <label><input type="checkbox" name="subjects[]" value="{{ $offering->subject_id }}" @checked(in_array($offering->subject_id, $chosen, true))> {{ $offering->subject->name }}</label>
                        @endforeach
                    </fieldset>
                    <p class="st-small" id="subjects-hint" style="margin:0">
                        @if ($offerings->isEmpty()) {{ __('academic.onboarding.subjects_none') }} @else Optional. Pick any you want help with. @endif
                    </p>
                    @if ($languageOfferings->isNotEmpty())
                        <details @if ($languageOfferings->contains(fn ($o) => in_array($o->subject_id, $chosen, true))) open @endif>
                            <summary class="td-link" style="cursor:pointer">{{ __('academic.onboarding.language_subjects', ['count' => $languageOfferings->count()]) }}</summary>
                            <fieldset class="st-chipset" style="margin-top:var(--td-space-3)">
                                <legend class="td-sr">{{ __('academic.onboarding.language_subjects', ['count' => $languageOfferings->count()]) }}</legend>
                                @foreach ($languageOfferings as $offering)
                                    <label><input type="checkbox" name="subjects[]" value="{{ $offering->subject_id }}" @checked(in_array($offering->subject_id, $chosen, true))> {{ $offering->subject->name }}</label>
                                @endforeach
                            </fieldset>
                        </details>
                    @endif
                    @error('subjects')<p class="td-error"><x-td.icon name="circle-alert" />{{ $message }}</p>@enderror

                    @if ($institutions->isNotEmpty())
                        <x-td.field :label="__('academic.onboarding.institution')" for="institution_id">
                            <x-td.select name="institution_id" :options="$institutions->mapWithKeys(fn ($i) => [$i->id => $i->name])->all()"
                                :value="$profile?->institution_id" :placeholder="__('academic.onboarding.institution_none')" />
                        </x-td.field>
                    @endif
                </div>
            </x-td.panel>

            <x-td.panel :title="__('academic.onboarding.languages_heading')">
                <div class="st-form">
                    @foreach ([
                        'teaching' => ['label' => __('academic.onboarding.teaching_languages'), 'hint' => __('academic.onboarding.teaching_languages_hint'), 'chosen' => $teaching],
                        'understands' => ['label' => __('academic.onboarding.understood_languages'), 'hint' => 'Optional.', 'chosen' => $understands],
                        'learning' => ['label' => __('academic.onboarding.learning_languages'), 'hint' => 'Optional.', 'chosen' => $learning],
                    ] as $field => $group)
                        <fieldset aria-describedby="{{ $field }}-hint">
                            <legend>{{ $group['label'] }}</legend>
                            <p class="td-hint" id="{{ $field }}-hint" style="margin:0 0 var(--td-space-2)">{{ $group['hint'] }}</p>
                            <div class="st-chipset">
                                @foreach ($languages as $language)
                                    <label><input type="checkbox" name="{{ $field }}[]" value="{{ $language->code }}" @checked(in_array($language->code, old($field, $group['chosen']), true))> <span lang="{{ $language->code }}">{{ $language->name_native }}</span></label>
                                @endforeach
                            </div>
                            @error($field)<p class="td-error"><x-td.icon name="circle-alert" />{{ $message }}</p>@enderror
                        </fieldset>
                    @endforeach

                    <fieldset>
                        <legend>{{ __('academic.onboarding.bilingual') }}</legend>
                        <div class="st-stack st-stack--tight">
                            <x-td.check type="radio" name="bilingual_ok" value="1" :checked="$bilingual === '1'">{{ __('academic.onboarding.bilingual_yes') }}</x-td.check>
                            <x-td.check type="radio" name="bilingual_ok" value="0" :checked="$bilingual === '0'">{{ __('academic.onboarding.bilingual_no') }}</x-td.check>
                        </div>
                    </fieldset>

                    @if ($isOnboarding && ! empty($localeOptions))
                        <x-td.field label="Language of this website" for="interface_locale" hint="This changes the words on screen only. It never decides which tutors you see.">
                            <x-td.select name="interface_locale" :hint="true" :value="$currentLocale" :options="collect($localeOptions)->map(fn ($o) => [
                                'value' => $o['code'],
                                'label' => $o['name_native'].' · '.match ($o['status']) { 'complete' => 'fully translated', 'partial' => 'partly translated'.($o['provenance'] === 'machine_unreviewed' ? ', AI-drafted, not yet checked' : ''), default => 'not translated yet, shown in English' },
                            ])->all()" />
                        </x-td.field>
                    @endif
                </div>
            </x-td.panel>

            <x-td.panel title="About you">
                <fieldset class="st-form" aria-describedby="birth-hint">
                    <legend>{{ __('academic.onboarding.birth_month') }}</legend>
                    <p class="td-hint" id="birth-hint" style="margin:0">{{ __('academic.onboarding.birth_month_hint') }} Required.</p>
                    <div class="st-form__pair">
                        <x-td.field :label="__('academic.onboarding.birth_month_label_month')" for="birth_month">
                            <x-td.select name="birth_month" required :placeholder="__('academic.onboarding.birth_month_label_month')"
                                :options="collect(__('academic.months'))->all()" :value="$profile?->birth_month?->month" />
                        </x-td.field>
                        <x-td.field :label="__('academic.onboarding.birth_month_label_year')" for="birth_year">
                            <x-td.select name="birth_year" required :placeholder="__('academic.onboarding.birth_month_label_year')"
                                :options="collect(range((int) now()->format('Y'), (int) now()->format('Y') - 100))->mapWithKeys(fn ($y) => [$y => (string) $y])->all()" :value="$profile?->birth_month?->year" />
                        </x-td.field>
                    </div>
                </fieldset>
            </x-td.panel>

            <div class="st-form__actions">
                <x-td.button type="submit" variant="primary" size="lg">{{ $isOnboarding ? 'Save and continue' : __('academic.onboarding.save') }}</x-td.button>
                @if ($isOnboarding)
                    <x-td.button variant="quiet" :href="route('learn.home')">Skip for now</x-td.button>
                @endif
            </div>
        </form>
    @else
        <x-td.notice tone="info">{{ __('academic.onboarding.choose_level_first') }}</x-td.notice>
        @if ($isOnboarding)
            <div class="st-form__actions"><x-td.button variant="quiet" :href="route('learn.home')">Skip for now</x-td.button></div>
        @endif
    @endif
</div>
