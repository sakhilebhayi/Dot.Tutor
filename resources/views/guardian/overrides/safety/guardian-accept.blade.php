{{-- Ops restyle of safety's guardian acceptance page (same data and the same form posting to
     safety.guardians.accept.store): versioned consent text, the learner's date of birth, optional recording consent. --}}
<x-layouts::learner :title="'Be '.$learnerName.'\'s guardian'">
    <x-td.page-head :title="'Be '.$learnerName.'\'s guardian on Dot.Tutor'" :lede="$learnerName.' named you as their '.str_replace('_', ' ', $invitation->relationship).'. Read what you agree to, confirm their date of birth, and give consent.'" />

    <div class="grid gap-6" style="max-width: 44rem">
        @unless ($emailMatches)
            <x-td.notice tone="danger" title="Wrong account">This invitation was sent to {{ $invitation->email }}. Sign in with that address to accept it.</x-td.notice>
        @endunless
        @if ($errors->any())
            <x-td.notice tone="danger" title="Not linked yet">@foreach ($errors->all() as $error)<p class="m-0">{{ $error }}</p>@endforeach</x-td.notice>
        @endif

        <form method="POST" action="{{ route('safety.guardians.accept.store', $invitation->id) }}" class="grid gap-6">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">

            <x-td.panel title="What you agree to">
                <div class="whitespace-pre-line" style="max-width: var(--td-measure)">{{ $consentText }}</div>
                <p class="mt-4 mb-4 text-sm ops-muted">Consent text version {{ $consentVersion }}.</p>
                <x-td.check name="consent" id="consent" value="1" required>I am {{ $learnerName }}'s parent or legal guardian and I agree.</x-td.check>
            </x-td.panel>

            <x-td.panel title="{{ $learnerName }}'s date of birth">
                <x-td.field :label="$learnerName.'\'s date of birth'" for="birth-date" hint="It decides which protections apply: under 13, or 13 to 17.">
                    <x-td.input name="birth_date" id="birth-date" type="date" required :max="now()->subDay()->toDateString()" hint />
                </x-td.field>
            </x-td.panel>

            <x-td.panel title="Lesson recordings (optional)">
                <div class="whitespace-pre-line mb-4" style="max-width: var(--td-measure)">{{ $recordingText }}</div>
                <input type="hidden" name="recording_consent" value="0">
                <x-td.check name="recording_consent" id="recording_consent" value="1">I agree to live lessons being recorded.</x-td.check>
            </x-td.panel>

            <div><x-td.button type="submit" variant="primary" size="lg" :disabled="! $emailMatches">Give consent and link</x-td.button></div>
        </form>
    </div>
</x-layouts::learner>
