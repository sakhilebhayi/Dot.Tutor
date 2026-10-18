<x-app-layout>

<div class="t-page">
    <h1 class="t-h1">Tutor review</h1>
    <p class="t-lede">
        Approve or reject applications. Every decision is sent to the applicant, with your reason, as a notice on their dashboard.
        Suspending an approved tutor takes them off the list and cancels their pending requests.
    </p>

    @if(session('status'))<div class="t-flash" role="status">{{ session('status') }}</div>@endif

    <h2 class="t-h2" style="margin-top:1rem;">Waiting for review <span class="t-mono" style="color:#a1a1aa;">{{ $pendingProfiles->count() }}</span></h2>
    @if($pendingProfiles->isEmpty())
        <p style="color:#a1a1aa;font-size:.85rem;margin:.5rem 0 1.5rem;">No tutor profiles awaiting review.</p>
    @endif

    @foreach($pendingProfiles as $profile)
    <div class="t-panel">
        <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
            <div>
                <p style="color:#f4f4f5;font-weight:700;margin:0;">{{ $profile->user?->name ?? 'Deleted account' }}</p>
                @if($profile->headline)<p style="color:#d4d4d8;font-size:.85rem;margin:.15rem 0 0;">{{ $profile->headline }}</p>@endif
            </div>
            <p class="t-mono" style="color:#fcd34d;font-size:.82rem;margin:0;">{{ \App\Support\Money::format($profile->hourly_rate, $profile->currency) }}/hr</p>
        </div>
        <p style="color:#a1a1aa;font-size:.78rem;margin:.5rem 0;">
            {{ $profile->subjects->pluck('name')->join(', ') ?: 'No subjects listed' }} &middot; {{ $profile->scheduleTimezone() }}
        </p>
        @if($profile->bio)<p style="color:#d4d4d8;font-size:.85rem;margin:.5rem 0;">{{ $profile->bio }}</p>@endif
        @if($profile->qualifications)
            <p style="color:#d4d4d8;font-size:.82rem;margin:.5rem 0;"><strong style="color:#a1a1aa;">Qualifications:</strong> {{ $profile->qualifications }}</p>
        @endif

        <div style="display:flex;gap:.75rem;align-items:flex-start;margin-top:1rem;flex-wrap:wrap;">
            <form method="POST" action="{{ route('operator.tutor-profiles.approve', $profile) }}">
                @csrf
                <button type="submit" class="t-btn t-btn-primary">Approve</button>
            </form>
            <form method="POST" action="{{ route('operator.tutor-profiles.reject', $profile) }}" style="flex:1;display:flex;gap:.5rem;min-width:16rem;">
                @csrf
                <label for="reject-{{ $profile->id }}" style="position:absolute;left:-9999px;">Reason for rejecting {{ $profile->user?->name }}</label>
                <input type="text" id="reject-{{ $profile->id }}" name="reason" class="t-field" placeholder="Reason for rejecting (the applicant sees this)" required>
                <button type="submit" class="t-btn t-btn-danger">Reject</button>
            </form>
        </div>
    </div>
    @endforeach

    <h2 class="t-h2" style="margin-top:2rem;">Approved <span class="t-mono" style="color:#a1a1aa;">{{ $approvedProfiles->count() }}</span></h2>
    @forelse($approvedProfiles as $profile)
    <div class="t-panel" style="display:flex;gap:1rem;align-items:center;justify-content:space-between;flex-wrap:wrap;padding:1rem 1.25rem;">
        <div>
            <span style="color:#f4f4f5;font-weight:600;">{{ $profile->user?->name ?? 'Deleted account' }}</span>
            <span style="color:#a1a1aa;font-size:.78rem;"> &middot; {{ $profile->subjects->pluck('name')->join(', ') }}</span>
        </div>
        <form method="POST" action="{{ route('operator.tutor-profiles.suspend', $profile) }}" style="display:flex;gap:.5rem;flex:1;max-width:30rem;min-width:14rem;">
            @csrf
            <label for="suspend-{{ $profile->id }}" style="position:absolute;left:-9999px;">Reason for suspending {{ $profile->user?->name }}</label>
            <input type="text" id="suspend-{{ $profile->id }}" name="reason" class="t-field" placeholder="Reason for suspending (the tutor sees this)" required>
            <button type="submit" class="t-btn t-btn-danger">Suspend</button>
        </form>
    </div>
    @empty
        <p style="color:#a1a1aa;font-size:.85rem;">No approved tutors yet.</p>
    @endforelse

    @if($suspendedProfiles->isNotEmpty())
    <h2 class="t-h2" style="margin-top:2rem;">Suspended <span class="t-mono" style="color:#a1a1aa;">{{ $suspendedProfiles->count() }}</span></h2>
    @foreach($suspendedProfiles as $profile)
    <div class="t-panel" style="display:flex;gap:1rem;align-items:center;justify-content:space-between;flex-wrap:wrap;padding:1rem 1.25rem;">
        <div>
            <span style="color:#f4f4f5;font-weight:600;">{{ $profile->user?->name ?? 'Deleted account' }}</span>
            <span style="color:#a1a1aa;font-size:.78rem;"> &middot; {{ $profile->suspended_reason }}</span>
        </div>
        @if($profile->user)
        <form method="POST" action="{{ route('operator.tutor-profiles.reinstate', $profile) }}">
            @csrf
            <button type="submit" class="t-btn t-btn-quiet">Reinstate</button>
        </form>
        @else
            <span style="color:#71717a;font-size:.78rem;">Account closed; kept for session history</span>
        @endif
    </div>
    @endforeach
    @endif
</div>
</x-app-layout>
