<x-app-layout>
<div class="t-page">
    <h1 class="t-h1">Evidence to review</h1>
    <p class="t-lede">Open each file before deciding. A verification is public: it tells learners Dot.Tutor checked this. Files are not virus-scanned on this host; open them with care.</p>
    @if(session('status'))<div class="t-flash" role="status">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="t-flash" role="alert">{{ $errors->first() }}</div>@endif

    @forelse($queue as $q)
        <section class="t-panel" aria-label="{{ $q->title }}">
            <h2 class="t-h2">{{ $q->title }} <span class="t-state">{{ $q->kindEnum()->label() }}</span></h2>
            <p class="t-hint" style="margin:0 0 .5rem;">{{ $q->tutorProfile->displayName() }} &middot; {{ $q->institution ?? 'No institution given' }} &middot; {{ $q->year_awarded ?? 'no year' }} &middot; sent {{ $q->submitted_at?->diffForHumans() }}</p>
            <ul style="margin:0 0 .75rem;padding-left:1.1rem;font-size:.8rem;">
                @foreach($q->evidence as $e)
                    <li><a href="{{ route('marketplace.api.operator.evidence', $e) }}" style="color:#fbbf24;">{{ $e->original_name }}</a> <span class="t-mono" style="color:#a1a1aa;">{{ $e->mime }}, {{ number_format($e->size_bytes / 1024) }} KB</span></li>
                @endforeach
            </ul>
            <form method="POST" action="{{ route('marketplace.operator.verification.decide', [$q, 'verify']) }}" style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:flex-end;">
                @csrf
                <div style="flex:1 1 16rem;"><label class="t-label" for="note-{{ $q->id }}">What you checked, or why not</label><input class="t-field" id="note-{{ $q->id }}" name="note" required minlength="5" maxlength="2000"></div>
                <button class="t-btn t-btn-primary" type="submit">Verify</button>
                <button class="t-btn t-btn-danger" type="submit" formaction="{{ route('marketplace.operator.verification.decide', [$q, 'reject']) }}">Reject</button>
            </form>
        </section>
    @empty
        <p class="t-hint">Nothing waiting for review.</p>
    @endforelse
</div>
</x-app-layout>
