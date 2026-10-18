<x-app-layout>
@php
    $status = $profile?->status;
    $reapplying = in_array($status, ['rejected', 'suspended'], true);
    $chosen = collect(old('subjects', $selectedSubjectIds))->map(fn ($id) => (int) $id)->all();
    $heading = match (true) {
        ! $profile => 'Apply to teach',
        $reapplying => 'Edit and re-apply',
        default => 'Your tutor profile',
    };
@endphp

<div class="t-page">
    <h1 class="t-h1">{{ $heading }}</h1>
    <p class="t-lede">
        @if(! $profile)
            Tell students who you are and what you teach. An operator reviews every application before you appear in the tutor list.
        @else
            This is what students see on your tutor page, along with the open times you set under <a href="{{ route('tutor.availability.index') }}" style="color:#fbbf24;">My hours</a>.
        @endif
    </p>

    @if(session('status'))<div class="t-flash" role="status">{{ session('status') }}</div>@endif

    @if($profile)
    <div class="t-panel" style="margin-bottom:1rem;">
        <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;">
            <span class="t-state t-state-{{ $status }}">{{ $status }}</span>
            <span style="font-size:.85rem;color:#d4d4d8;">
                @switch($status)
                    @case('pending') An operator has not reviewed this yet. You can keep editing while you wait. @break
                    @case('approved') You are listed in the tutor directory. @break
                    @case('rejected') Not approved. Read the reason, change what it points at, then re-apply below. @break
                    @case('suspended') You are not listed and cannot take new bookings. Read the reason, change what it points at, then re-apply below. @break
                @endswitch
            </span>
        </div>
        @if($profile->decisionReason())
            <p style="margin:.9rem 0 0;font-size:.85rem;color:#fcd34d;">
                <strong style="color:#f4f4f5;">Reason given:</strong> {{ $profile->decisionReason() }}
            </p>
        @endif
    </div>
    @endif

    @if($subjects->isEmpty())
        <div class="t-panel">
            <p style="margin:0;font-size:.85rem;color:#d4d4d8;">
                There are no subjects in the catalogue yet, so there is nothing to teach against.
                An operator adds subjects under Operator &rarr; Subjects; come back after that.
            </p>
        </div>
    @else
    <form method="POST" action="{{ $profile ? route('tutor.profile.update') : route('tutor.profile.store') }}" class="t-panel">
        @csrf
        @if($profile) @method('PUT') @endif

        <div class="t-row">
            <label class="t-label" for="headline">Headline</label>
            <input class="t-field" id="headline" name="headline" maxlength="160" required
                   value="{{ old('headline', $profile?->headline) }}" placeholder="One line a student can judge you by">
            @error('headline')<div class="t-err">{{ $message }}</div>@enderror
        </div>

        <div class="t-row">
            <label class="t-label" for="bio">About you</label>
            <textarea class="t-field" id="bio" name="bio" rows="5" maxlength="4000" required>{{ old('bio', $profile?->bio) }}</textarea>
            @error('bio')<div class="t-err">{{ $message }}</div>@enderror
        </div>

        <div class="t-row">
            <label class="t-label" for="qualifications">Qualifications and experience</label>
            <textarea class="t-field" id="qualifications" name="qualifications" rows="3" maxlength="4000" required>{{ old('qualifications', $profile?->qualifications) }}</textarea>
            <p class="t-hint" style="margin:.35rem 0 0;">Degrees, certificates, years teaching. Operators read this when deciding.</p>
            @error('qualifications')<div class="t-err">{{ $message }}</div>@enderror
        </div>

        <fieldset class="t-row" style="border:0;padding:0;margin:0 0 1rem;">
            <legend class="t-label">Subjects you teach</legend>
            <div style="display:flex;flex-wrap:wrap;gap:.5rem;">
                @foreach($subjects as $subject)
                <label class="t-chip">
                    <input type="checkbox" name="subjects[]" value="{{ $subject->id }}" @checked(in_array($subject->id, $chosen, true))>
                    <span>{{ $subject->name }} <small style="opacity:.7;">{{ \App\Models\Subject::LEVELS[$subject->level] ?? $subject->level }}</small></span>
                </label>
                @endforeach
            </div>
            @error('subjects')<div class="t-err">{{ $message }}</div>@enderror
            @error('subjects.*')<div class="t-err">{{ $message }}</div>@enderror
        </fieldset>

        <div class="t-grid2 t-row">
            <div>
                <label class="t-label" for="hourly_rate">Hourly rate ({{ $currency }})</label>
                <input class="t-field t-mono" id="hourly_rate" name="hourly_rate" inputmode="decimal" required
                       value="{{ old('hourly_rate', $profile ? number_format((float) $profile->hourly_rate, 2, '.', '') : '') }}" placeholder="350.00">
                @error('hourly_rate')<div class="t-err">{{ $message }}</div>@enderror
            </div>
            <div>
                <label class="t-label" for="timezone">Your timezone</label>
                <input class="t-field" id="timezone" name="timezone" list="timezone-list" required
                       value="{{ old('timezone', $profile?->scheduleTimezone() ?? $defaultTimezone) }}">
                <datalist id="timezone-list">
                    @foreach($timezones as $tz)<option value="{{ $tz }}">@endforeach
                </datalist>
                @error('timezone')<div class="t-err">{{ $message }}</div>@enderror
            </div>
        </div>

        <p class="t-hint">
            Changing your rate only affects sessions booked from now on; a session keeps the rate it was booked at.
            Payment is arranged outside the platform for now, so this is the rate you and a student agree to, not a charge.
        </p>

        <button type="submit" class="t-btn t-btn-primary">
            @if(! $profile) Send application @elseif($reapplying) Re-apply @else Save changes @endif
        </button>
    </form>
    @endif
</div>
</x-app-layout>
