<x-app-layout>

<style>
    .panel {
        background:#141416;
        border: 1px solid rgba(255,255,255,0.07);
        border-radius: 12px;
        overflow: hidden;
    }
    .filter-chip {
        display: inline-flex; align-items: center;
        padding: 0.45rem 1rem; border-radius: 9999px;
        font-size: 0.75rem; font-weight: 600;
        border: 1px solid rgba(67,70,86,0.3);
        color: #a1a1aa; text-decoration: none;
        transition: border-color 0.15s, color 0.15s, background 0.15s;
    }
    .filter-chip:hover { border-color: rgba(99,102,241,0.4); color: #f4f4f5; }
    .filter-chip.active { background: rgba(99,102,241,0.15); border-color: rgba(99,102,241,0.5); color: #a5b4fc; }

    .tutor-card {
        background:#141416;
        border: 1px solid rgba(255,255,255,0.07);
        border-radius: 12px;
        padding: 1.5rem;
        display: flex; flex-direction: column; gap: 0.75rem;
        text-decoration: none;
        transition: border-color 0.2s;
    }
    .tutor-card:hover { border-color: rgba(99,102,241,0.35); }
    .avatar-lg {
        width: 48px; height: 48px; border-radius: 9999px;
        background: #6366f1; display: flex; align-items: center; justify-content: center;
        font-size: 1rem; font-weight: 700; color: #fff; flex-shrink: 0;
    }
    .tutor-name { font-family:'Syne',sans-serif; font-size: 0.95rem; font-weight: 700; color:#f4f4f5; }
    .tutor-rate { font-size: 0.8rem; color: #fbbf24; font-weight: 700; }
    .tutor-bio { font-size: 0.78rem; color: #a1a1aa; line-height: 1.5; }
    .subject-pill {
        display: inline-block; font-size: 0.65rem; font-weight: 600;
        color: #71717a; background: rgba(67,70,86,0.25);
        border-radius: 9999px; padding: 0.15rem 0.55rem; margin: 0 0.3rem 0.3rem 0;
    }
</style>

<div style="padding: 2rem 2.5rem; max-width: 1400px;">

    <div style="margin-bottom:1.5rem;">
        <h1 style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:800;color:#f4f4f5;margin:0 0 0.2rem;">
            Find a Tutor
        </h1>
        <p style="font-size:0.8rem;color:#71717a;margin:0;">
            {{ $tutors->total() }} approved {{ Str::plural('tutor', $tutors->total()) }} available
        </p>
    </div>

    <div style="display:flex;flex-wrap:wrap;gap:0.5rem;margin-bottom:1.5rem;">
        <a href="{{ route('tutors.browse') }}" class="filter-chip {{ $selectedSubjectId ? '' : 'active' }}">All subjects</a>
        @foreach($subjects as $subject)
        <a href="{{ route('tutors.browse', ['subject' => $subject->id]) }}"
           class="filter-chip {{ $selectedSubjectId === $subject->id ? 'active' : '' }}">
            {{ $subject->name }}
        </a>
        @endforeach
    </div>

    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;">
        @forelse($tutors as $tutor)
        <a href="{{ route('tutors.show', $tutor) }}" class="tutor-card">
            <div style="display:flex;align-items:center;gap:0.75rem;">
                <div class="avatar-lg">{{ strtoupper(substr(optional($tutor->user)->name ?? '?', 0, 1)) }}</div>
                <div>
                    <div class="tutor-name">{{ optional($tutor->user)->name ?? 'Unnamed tutor' }}</div>
                    <div class="tutor-rate t-mono">{{ \App\Support\Money::format($tutor->hourly_rate, $tutor->currency, 0) }}/hr</div>
                </div>
            </div>
            @if($tutor->headline)
            <div style="font-size:0.82rem;color:#e4e4e7;font-weight:600;">{{ $tutor->headline }}</div>
            @endif
            @if($tutor->bio)
            <div class="tutor-bio">{{ Str::limit($tutor->bio, 120) }}</div>
            @endif
            <div>
                @foreach($tutor->subjects as $subject)
                <span class="subject-pill">{{ $subject->name }}</span>
                @endforeach
            </div>
        </a>
        @empty
        <div class="panel" style="grid-column:span 3;padding:2.5rem;text-align:center;color:#71717a;font-size:0.85rem;">
            @if($selectedSubjectId)
                No approved tutor teaches this subject yet.
            @else
                No tutors are listed yet.
            @endif
            @if(! auth()->user()->tutorProfile)
                <a href="{{ route('tutor.profile.edit') }}" style="color:#fbbf24;">Apply to teach</a>.
            @endif
        </div>
        @endforelse
    </div>

    <div style="margin-top:1.5rem;">
        {{ $tutors->links() }}
    </div>

</div>

</x-app-layout>
