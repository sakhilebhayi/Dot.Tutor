<x-app-layout>
<x-safety::frame>
    <h1>Be {{ $learnerName }}'s guardian on Dot.Tutor</h1>
    <p class="lede">{{ $learnerName }} named you as their {{ str_replace('_', ' ', $invitation->relationship) }}. Read what you agree to, confirm their date of birth, and give consent.</p>

    @unless ($emailMatches)
        <div class="err" role="alert">This invitation was sent to {{ $invitation->email }}. Sign in with that address to accept it.</div>
    @endunless

    <form method="POST" action="{{ route('safety.guardians.accept.store', $invitation->id) }}">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <h2>What you agree to</h2>
        <div class="consent">{{ $consentText }}</div>
        <p class="muted">Consent text version {{ $consentVersion }}.</p>
        <label class="check"><input type="checkbox" name="consent" value="1" required> I am {{ $learnerName }}'s parent or legal guardian and I agree.</label>

        <label for="birth-date">{{ $learnerName }}'s date of birth</label>
        <input id="birth-date" type="date" name="birth_date" required max="{{ now()->subDay()->toDateString() }}" value="{{ old('birth_date') }}">

        <h2>Lesson recordings (optional)</h2>
        <div class="consent">{{ $recordingText }}</div>
        <input type="hidden" name="recording_consent" value="0">
        <label class="check"><input type="checkbox" name="recording_consent" value="1"> I agree to live lessons being recorded.</label>

        <button type="submit" @disabled(! $emailMatches)>Give consent and link</button>
    </form>
</x-safety::frame>
</x-app-layout>
