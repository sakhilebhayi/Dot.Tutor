<x-app-layout>
<x-safety::frame>
    <h1>Safety and guardians</h1>
    <p class="lede">Who can see your account, and what waits for a parent or guardian.</p>

    <div class="panel">
        <div class="row"><span>Age group</span><strong>{{ ['child' => 'Under 13', 'teen' => '13 to 17', 'adult' => '18 or older'][$account['age_band']] }}{{ $account['age_known'] ? '' : ' (date of birth not given yet)' }}</strong></div>
        <div class="row"><span>Account</span><span class="tag tag-{{ $account['status'] }}">{{ ucfirst($account['status']) }}</span></div>
    </div>

    @if ($account['status'] === 'limited')
        <div class="notice" role="status">Learners under 18 need a parent or guardian to link to this account and give consent before booking, paying or messaging tutors. Until then you can browse, report a problem and contact support.</div>
    @endif

    @if ($account['is_minor'])
        <h2>Your parent or guardian</h2>
        @forelse ($guardians as $guardian)
            <div class="row"><span>{{ $guardian['name'] }}</span><span class="tag tag-active">Linked</span></div>
        @empty
            <p class="muted">Nobody is linked yet.</p>
        @endforelse
        @foreach ($pending_invitations as $invitation)
            <div class="row"><span>Invitation sent to {{ $invitation->email }}</span><span class="muted">Valid until {{ $invitation->expires_at->timezone(config('tutor.timezone'))->format('j M, H:i') }}</span></div>
        @endforeach

        <form method="POST" action="{{ route('safety.guardians.invite') }}" class="panel">
            @csrf
            <h3>Invite a parent or guardian</h3>
            <label for="guardian-email">Their email address</label>
            <input id="guardian-email" type="email" name="email" required autocomplete="off" value="{{ old('email') }}">
            <label for="guardian-relationship">They are your</label>
            <select id="guardian-relationship" name="relationship">
                <option value="parent">Parent</option>
                <option value="legal_guardian">Legal guardian</option>
                <option value="caregiver">Caregiver</option>
            </select>
            <button type="submit">Send invitation</button>
        </form>
    @endif

    @if ($approvals !== [])
        <h2>Waiting for approval</h2>
        @foreach ($approvals as $approval)
            <div class="row"><span>{{ $approval['summary'] ?? str_replace('_', ' ', $approval['kind']) }}</span><span class="tag tag-{{ $approval['status'] }}">{{ ucfirst($approval['status']) }}</span></div>
        @endforeach
    @endif

    <h2>Need help?</h2>
    <p><a href="{{ route('safety.conversations.index') }}">Messages and support</a>@if ($is_guardian) · <a href="{{ route('safety.guardian.dashboard') }}">Your guardian page</a>@endif</p>
</x-safety::frame>
</x-app-layout>
