{{-- Wave-1 functional view on the existing t-* classes; wave 2 restyles it on the design system. --}}
<x-app-layout>
<div class="t-page">
    <h1 class="t-h1">{{ __('academic.onboarding.title') }}</h1>
    <p class="t-lede">{{ __('academic.onboarding.lede') }}</p>

    @if(session('status'))<div class="t-flash" role="status">{{ session('status') }}</div>@endif

    {{-- 1. Plain words in, form filled out. --}}
    <form method="GET" action="{{ route('academic.profile.edit') }}" class="t-panel" role="search">
        <div class="t-row">
            <label class="t-label" for="need">{{ __('academic.onboarding.describe_label') }}</label>
            <input class="t-field" id="need" name="need" maxlength="500" value="{{ $need }}" placeholder="{{ __('academic.onboarding.describe_hint') }}">
        </div>
        <button type="submit" class="t-btn t-btn-quiet">{{ __('academic.onboarding.describe_button') }}</button>

        @if($parsed)
            <div class="t-hint" role="status" style="margin-top:1rem;" data-testid="need-result">
                @if($parsed['levels'] || $parsed['subjects'])
                    {{ __('academic.onboarding.describe_result', ['text' => $need]) }}
                @else
                    {{ __('academic.onboarding.describe_nothing', ['text' => $need]) }}
                @endif
                @foreach($parsed['notes'] as $note)
                    @if($note['key'] === 'not_offered')
                        <br>{{ __('academic.onboarding.not_offered', ['subject' => $note['subject'], 'level' => $note['level']]) }}
                    @endif
                @endforeach
            </div>
        @endif
    </form>

    {{-- 2. Country and level; choosing reloads with that level's subjects (no JavaScript needed). --}}
    <form method="GET" action="{{ route('academic.profile.edit') }}" class="t-panel">
        <div class="t-row">
            <label class="t-label" for="country">{{ __('academic.onboarding.country') }}</label>
            <select class="t-field" id="country" name="country">
                @foreach($countries as $country)
                    <option value="{{ $country->id }}" @selected($countryId === $country->id)>{{ $country->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="t-row">
            <label class="t-label" for="level">{{ __('academic.onboarding.level') }}</label>
            <select class="t-field" id="level" name="level" aria-describedby="level-hint">
                <option value="">{{ __('academic.onboarding.choose_level') }}</option>
                @foreach($curricula as $curriculum)
                    <optgroup label="{{ $curriculum->label() }}">
                        @foreach($curriculum->levels as $option)
                            <option value="{{ $option->id }}" @selected($level?->id === $option->id)>{{ $option->name }}</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
            <p class="t-hint" id="level-hint">{{ __('academic.onboarding.curriculum_hint') }}</p>
        </div>
        <button type="submit" class="t-btn t-btn-quiet">{{ __('academic.onboarding.show_subjects') }}</button>
    </form>

    {{-- 3. The profile itself. --}}
    @if($level)
    <form method="POST" action="{{ route('academic.profile.update') }}" class="t-panel">
        @csrf @method('PUT')
        <input type="hidden" name="country_id" value="{{ $countryId }}">
        <input type="hidden" name="academic_level_id" value="{{ $level->id }}">

        <p class="t-hint"><strong>{{ __('academic.onboarding.curriculum') }}:</strong> {{ $level->curriculum->label() }} &middot; <strong>{{ __('academic.onboarding.level') }}:</strong> {{ $level->name }}</p>
        @error('academic_level_id')<div class="t-err" role="alert">{{ $message }}</div>@enderror
        @error('country_id')<div class="t-err" role="alert">{{ $message }}</div>@enderror

        <fieldset class="t-row" style="border:0;padding:0;margin:0 0 1rem;">
            <legend class="t-label">{{ __('academic.onboarding.subjects') }}</legend>
            @php($chosen = collect(old('subjects', $subjectIds))->map(fn ($id) => (int) $id)->all())
            <div style="display:flex;flex-wrap:wrap;gap:.4rem;">
                @forelse($offerings as $offering)
                    <label class="t-chip">
                        <input type="checkbox" name="subjects[]" value="{{ $offering->subject_id }}" @checked(in_array($offering->subject_id, $chosen, true))>
                        <span>{{ $offering->subject->name }}</span>
                    </label>
                @empty
                    <p class="t-hint">{{ __('academic.onboarding.subjects_none') }}</p>
                @endforelse
            </div>
            @error('subjects')<div class="t-err" role="alert">{{ $message }}</div>@enderror
        </fieldset>

        @if($institutions->isNotEmpty())
            <div class="t-row">
                <label class="t-label" for="institution_id">{{ __('academic.onboarding.institution') }}</label>
                <select class="t-field" id="institution_id" name="institution_id">
                    <option value="">{{ __('academic.onboarding.institution_none') }}</option>
                    @foreach($institutions as $institution)
                        <option value="{{ $institution->id }}" @selected((int) old('institution_id', $profile?->institution_id) === $institution->id)>{{ $institution->name }}</option>
                    @endforeach
                </select>
            </div>
        @endif

        <fieldset class="t-row" style="border:0;padding:0;margin:0 0 1rem;" aria-describedby="birth-hint">
            <legend class="t-label">{{ __('academic.onboarding.birth_month') }}</legend>
            <p class="t-hint" id="birth-hint">{{ __('academic.onboarding.birth_month_hint') }}</p>
            <div style="display:flex;flex-wrap:wrap;gap:.5rem;">
                <label style="position:absolute;left:-9999px;" for="birth_month">{{ __('academic.onboarding.birth_month_label_month') }}</label>
                <select class="t-field" id="birth_month" name="birth_month" required style="width:auto;min-width:9rem;">
                    <option value="">{{ __('academic.onboarding.birth_month_label_month') }}</option>
                    @foreach(__('academic.months') as $number => $name)
                        <option value="{{ $number }}" @selected((int) old('birth_month', $profile?->birth_month?->month) === $number)>{{ $name }}</option>
                    @endforeach
                </select>
                <label style="position:absolute;left:-9999px;" for="birth_year">{{ __('academic.onboarding.birth_month_label_year') }}</label>
                <select class="t-field" id="birth_year" name="birth_year" required style="width:auto;min-width:7rem;">
                    <option value="">{{ __('academic.onboarding.birth_month_label_year') }}</option>
                    @for($year = (int) now()->format('Y'); $year >= (int) now()->format('Y') - 100; $year--)
                        <option value="{{ $year }}" @selected((int) old('birth_year', $profile?->birth_month?->year) === $year)>{{ $year }}</option>
                    @endfor
                </select>
            </div>
            @error('birth_month')<div class="t-err" role="alert">{{ $message }}</div>@enderror
            @error('birth_year')<div class="t-err" role="alert">{{ $message }}</div>@enderror
        </fieldset>

        <h2 class="t-h2">{{ __('academic.onboarding.languages_heading') }}</h2>
        @foreach([
            'teaching' => ['label' => __('academic.onboarding.teaching_languages'), 'hint' => __('academic.onboarding.teaching_languages_hint'), 'chosen' => $teaching],
            'understands' => ['label' => __('academic.onboarding.understood_languages'), 'hint' => null, 'chosen' => $understands],
            'learning' => ['label' => __('academic.onboarding.learning_languages'), 'hint' => null, 'chosen' => $learning],
        ] as $field => $group)
            <fieldset class="t-row" style="border:0;padding:0;margin:0 0 1rem;">
                <legend class="t-label">{{ $group['label'] }}</legend>
                @if($group['hint'])<p class="t-hint">{{ $group['hint'] }}</p>@endif
                <div style="display:flex;flex-wrap:wrap;gap:.4rem;">
                    @foreach($languages as $language)
                        <label class="t-chip">
                            <input type="checkbox" name="{{ $field }}[]" value="{{ $language->code }}" @checked(in_array($language->code, old($field, $group['chosen']), true))>
                            <span lang="{{ $language->code }}">{{ $language->name_native }}</span>
                        </label>
                    @endforeach
                </div>
                @error($field)<div class="t-err" role="alert">{{ $message }}</div>@enderror
            </fieldset>
        @endforeach

        <fieldset class="t-row" style="border:0;padding:0;margin:0 0 1rem;">
            <legend class="t-label">{{ __('academic.onboarding.bilingual') }}</legend>
            @php($bilingual = old('bilingual_ok', $profile?->bilingual_ok === null ? null : ($profile->bilingual_ok ? '1' : '0')))
            <label style="display:block;margin-bottom:.3rem;"><input type="radio" name="bilingual_ok" value="1" @checked($bilingual === '1')> {{ __('academic.onboarding.bilingual_yes') }}</label>
            <label style="display:block;"><input type="radio" name="bilingual_ok" value="0" @checked($bilingual === '0')> {{ __('academic.onboarding.bilingual_no') }}</label>
        </fieldset>

        <button type="submit" class="t-btn t-btn-primary">{{ __('academic.onboarding.save') }}</button>
    </form>
    @else
        <p class="t-hint">{{ __('academic.onboarding.choose_level_first') }}</p>
    @endif
</div>
</x-app-layout>
