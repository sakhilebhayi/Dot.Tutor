<x-app-layout>

@php
    $user = auth()->user();
    $needs = [];
    foreach ($metrics['lenses'] as $lens) {
        $f = collect($lens['figures'])->pluck('value', 'key');
        if ($lens['key'] === 'platform' && ($f['applications_pending'] ?? 0) > 0) {
            $n = $f['applications_pending'];
            $needs[] = ['text' => $n.' tutor '.Str::plural('application', $n).' waiting for review', 'url' => route('operator.tutor-profiles.index')];
        }
    }
    if ($tutorProfile && $tutorProfile->status !== 'approved') {
        $needs[] = ['text' => 'Your tutor profile is '.$tutorProfile->status, 'url' => route('tutor.profile.edit')];
    }
    if ($tutorProfile && $tutorProfile->isApproved()) {
        $waiting = $upcomingSessionsList->filter(fn ($s) => $s->status === 'pending' && $s->tutorProfile->user_id === $user->id)->count();
        if ($waiting > 0) {
            $needs[] = ['text' => $waiting.' booking '.Str::plural('request', $waiting).' waiting for you to confirm', 'url' => route('sessions.show', $upcomingSessionsList->first(fn ($s) => $s->status === 'pending' && $s->tutorProfile->user_id === $user->id))];
        }
    }
@endphp

<style>
    .readout { display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:1px; background:rgba(255,255,255,0.12); border:1px solid rgba(255,255,255,0.12); border-radius:12px; overflow:hidden; }
    .readout > div { background:#141416; padding:1rem 1.25rem; }
    .readout dt { font-size:.66rem; font-weight:700; color:#a1a1aa; text-transform:uppercase; letter-spacing:.1em; }
    .readout dd { margin:.35rem 0 0; font-family:'JetBrains Mono',monospace; font-variant-numeric:tabular-nums; font-size:1.7rem; font-weight:500; color:#f4f4f5; line-height:1; }
    .readout dd .ghost { color:#52525b; }
    .panel { background:#141416; border:1px solid rgba(255,255,255,0.07); border-radius:12px; overflow:hidden; }
    .panel-header { padding:1rem 1.5rem; border-bottom:1px solid rgba(255,255,255,0.08); display:flex; align-items:center; gap:.6rem; }
    .panel-title { font-family:'Syne',sans-serif; font-size:.875rem; font-weight:700; color:#f4f4f5; }
    .panel-count { font-size:.7rem; font-weight:700; background:rgba(251,191,36,0.12); color:#fbbf24; padding:.15rem .55rem; border-radius:9999px; }
    .session-row { display:flex; align-items:center; gap:1rem; padding:.9rem 1.5rem; border-bottom:1px solid rgba(255,255,255,0.06); }
    .session-row:last-child { border-bottom:none; }
    .session-row:hover { background:rgba(255,255,255,0.03); }
    .avatar-sm { width:32px; height:32px; border-radius:9999px; background:rgba(251,191,36,0.16); border:1px solid rgba(251,191,36,0.4); display:flex; align-items:center; justify-content:center; font-size:.7rem; font-weight:700; color:#fbbf24; flex-shrink:0; }
    .status-badge { display:inline-flex; align-items:center; padding:.2rem .6rem; border-radius:9999px; font-size:.65rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; }
    .badge-pending { background:rgba(234,179,8,0.12); color:#fcd34d; }
    .badge-confirmed { background:rgba(34,197,94,0.12); color:#86efac; }
    .badge-completed { background:rgba(255,255,255,0.08); color:#d4d4d8; }
    .badge-cancelled { background:rgba(239,68,68,0.12); color:#fca5a5; }
    .badge-no_show { background:rgba(161,161,170,0.15); color:#a1a1aa; }
    .data-table { width:100%; border-collapse:collapse; }
    .data-table th { padding:.75rem 1.25rem; font-size:.65rem; font-weight:700; color:#a1a1aa; text-transform:uppercase; letter-spacing:.1em; text-align:left; border-bottom:1px solid rgba(255,255,255,0.08); }
    .data-table td { padding:.85rem 1.25rem; font-size:.8rem; color:#d4d4d8; border-bottom:1px solid rgba(255,255,255,0.05); vertical-align:middle; }
    .data-table tr:last-child td { border-bottom:none; }
    .data-table .tutor-cell { display:flex; align-items:center; gap:.6rem; }
    .data-table .dim { color:#a1a1aa; }
    .subject-line { display:flex; justify-content:space-between; gap:1rem; padding:.55rem 1.5rem; border-bottom:1px solid rgba(255,255,255,0.05); font-size:.82rem; color:#d4d4d8; }
    .subject-line:last-child { border-bottom:none; }
    @media (max-width: 900px) { .dash-two { grid-template-columns:1fr !important; } }
    @media (max-width: 640px) {
        .session-row { flex-wrap:wrap; gap:.5rem .75rem; padding:.9rem 1rem; }
        .session-row > div:nth-child(2) { flex:1 1 calc(100% - 3rem) !important; }
        .session-row > div:nth-child(3) { text-align:left !important; margin-left:2.75rem; }
        .session-row .status-badge { margin-left:auto; }
        .panel-header, .subject-line { padding-left:1rem; padding-right:1rem; }
    }
</style>

<div class="t-page" style="max-width:1400px;">

    <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:1.25rem;gap:1rem;flex-wrap:wrap;">
        <div>
            <h1 class="t-h1">Dashboard</h1>
            <p style="font-size:.8rem;color:#a1a1aa;margin:0;">{{ now()->format('l, F j, Y') }}</p>
        </div>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
            @unless($tutorProfile)
                <a href="{{ route('tutor.profile.edit') }}" class="t-btn t-btn-quiet">Apply to teach</a>
            @endunless
            <a href="{{ route('tutors.browse') }}" class="t-btn t-btn-primary">Find a tutor</a>
        </div>
    </div>

    {{-- State bar: answers "does anything need me?" in words before any figure. --}}
    <div class="t-panel" style="padding:.9rem 1.25rem;margin-bottom:1rem;" role="status">
        @if(count($needs))
            @foreach($needs as $need)
                <div style="font-size:.88rem;{{ $loop->last ? '' : 'margin-bottom:.4rem;' }}">
                    <span style="color:#fbbf24;font-weight:700;">Needs you:</span>
                    <a href="{{ $need['url'] }}" style="color:#f4f4f5;">{{ $need['text'] }}</a>
                </div>
            @endforeach
        @else
            <div style="font-size:.88rem;color:#d4d4d8;">Nothing needs you right now.</div>
        @endif
    </div>

    @if($notices->isNotEmpty())
    <div class="t-panel" style="margin-bottom:1rem;">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;margin-bottom:.5rem;">
            <h2 class="t-h2" style="margin:0;">Notices</h2>
            <form method="POST" action="{{ route('notifications.read') }}">@csrf
                <button type="submit" class="t-btn t-btn-quiet" style="padding:.3rem .7rem;">Mark all read</button>
            </form>
        </div>
        @foreach($notices as $notice)
            <div style="padding:.6rem 0;border-top:1px solid rgba(255,255,255,0.07);">
                <div style="font-size:.85rem;color:#f4f4f5;">
                    @if(! empty($notice->data['url']))<a href="{{ $notice->data['url'] }}" style="color:inherit;">{{ $notice->data['message'] ?? 'Update' }}</a>@else{{ $notice->data['message'] ?? 'Update' }}@endif
                </div>
                @if(! empty($notice->data['reason']))
                    <div style="font-size:.8rem;color:#fcd34d;margin-top:.15rem;">Reason: {{ $notice->data['reason'] }}</div>
                @endif
                <div style="font-size:.7rem;color:#71717a;margin-top:.15rem;">{{ $notice->created_at->diffForHumans() }}</div>
            </div>
        @endforeach
    </div>
    @endif

    {{-- Figures: scoped to who is looking. A student never sees platform totals. --}}
    @foreach($metrics['lenses'] as $lens)
    <section aria-label="{{ $lens['title'] }} figures" style="margin-bottom:1.25rem;">
        <h2 class="t-label" style="margin:0 0 .5rem;">{{ $lens['title'] }}</h2>
        <dl class="readout">
            @foreach($lens['figures'] as $figure)
            <div>
                <dt>{{ $figure['label'] }}</dt>
                <dd>
                    @if($figure['key'] === 'value_completed')
                        <span style="font-size:.7rem;color:#a1a1aa;">{{ config('tutor.currency') }}</span> {{ number_format($figure['value'], 2) }}
                    @else
                        @php $padded = str_pad((string) (int) $figure['value'], 3, '0', STR_PAD_LEFT); $real = (string) (int) $figure['value']; @endphp
                        <span class="sr-only" style="position:absolute;left:-9999px;">{{ $real }}</span>
                        <span aria-hidden="true"><span class="ghost">{{ substr($padded, 0, strlen($padded) - strlen($real)) }}</span>{{ $real }}</span>
                    @endif
                </dd>
            </div>
            @endforeach
        </dl>
        @foreach($lens['other_currencies'] as $code => $amount)
            <p style="font-size:.72rem;color:#a1a1aa;margin:.4rem 0 0;">Also {{ \App\Support\Money::format($amount, $code) }} in sessions booked under {{ $code }}.</p>
        @endforeach
    </section>
    @endforeach
    <p style="font-size:.74rem;color:#a1a1aa;margin:0 0 1.5rem;max-width:70ch;">
        Payment is arranged outside the platform for now. "Completed lesson value" is rate &times; time at the rate agreed when each session was booked, not money collected.
    </p>

    <div class="dash-two" style="display:grid;grid-template-columns:1fr 1.5fr;gap:1.25rem;margin-bottom:1.25rem;">

        <div class="panel">
            <div class="panel-header">
                <span class="panel-title">Subjects</span>
                <span class="panel-count">{{ $subjects->count() }}</span>
            </div>
            @forelse($subjects as $subject)
            <div class="subject-line">
                <span>{{ $subject->name }} <span style="color:#71717a;">&middot; {{ \App\Models\Subject::LEVELS[$subject->level] ?? ucfirst(str_replace('_',' ',$subject->level)) }}</span></span>
                <span class="t-mono" style="color:#a1a1aa;">{{ $subject->tutors_count }} {{ Str::plural('tutor', $subject->tutors_count) }}</span>
            </div>
            @empty
            <div style="padding:1.5rem;text-align:center;color:#a1a1aa;font-size:.8rem;">
                No subjects in the catalogue yet.
                @if($user->is_platform_operator)<a href="{{ route('operator.subjects.index') }}" style="color:#fbbf24;">Add some</a>.@endif
            </div>
            @endforelse
        </div>

        <div class="panel">
            <div class="panel-header">
                <span class="panel-title">Upcoming sessions</span>
                <span class="panel-count">{{ $upcomingSessionsList->count() }}</span>
            </div>
            @forelse($upcomingSessionsList as $session)
            @php
                $iAmTutor = $session->tutorProfile->user_id === $user->id;
                $other = $iAmTutor ? optional($session->student)->name : optional($session->tutorProfile->user)->name;
                $other = $other ?? 'Deleted account';
            @endphp
            <a href="{{ route('sessions.show', $session) }}" class="session-row" style="text-decoration:none;color:inherit;">
                <div class="avatar-sm">{{ strtoupper(substr($other, 0, 1)) }}</div>
                <div style="flex:1;min-width:0;">
                    <div style="font-size:.8rem;font-weight:600;color:#f4f4f5;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $other }}</div>
                    <div style="font-size:.7rem;color:#a1a1aa;">{{ optional($session->subject)->name ?? '—' }} &middot; {{ $iAmTutor ? 'you teach' : 'you learn' }}</div>
                </div>
                <div style="text-align:right;flex-shrink:0;">
                    <div class="t-mono" style="font-size:.75rem;font-weight:600;color:#d4d4d8;">{{ $session->starts_at->format('M j, H:i') }} UTC</div>
                    <div style="font-size:.68rem;color:#a1a1aa;">{{ $session->duration_minutes }} min</div>
                </div>
                <span class="status-badge badge-{{ $session->status }}">{{ str_replace('_', ' ', $session->status) }}</span>
            </a>
            @empty
            <div style="padding:2.5rem;text-align:center;color:#a1a1aa;font-size:.8rem;">
                No upcoming sessions. <a href="{{ route('tutors.browse') }}" style="color:#fbbf24;">Find a tutor</a>.
            </div>
            @endforelse
        </div>
    </div>

    <div class="panel">
        <div class="panel-header">
            <span class="panel-title">Recent sessions</span>
            <span class="panel-count">{{ $recentSessions->count() }}</span>
        </div>
        <div class="table-scroll" style="overflow-x:auto;" role="region" aria-label="Recent sessions" tabindex="0">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>With</th><th>Subject</th><th>Date</th><th>Duration</th><th>Status</th><th style="text-align:right;">Agreed value</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($recentSessions as $session)
                    @php
                        $iAmTutor = $session->tutorProfile->user_id === $user->id;
                        $other = ($iAmTutor ? optional($session->student)->name : optional($session->tutorProfile->user)->name) ?? 'Deleted account';
                    @endphp
                    <tr>
                        <td><div class="tutor-cell"><div class="avatar-sm">{{ strtoupper(substr($other, 0, 1)) }}</div><span style="font-weight:600;color:#f4f4f5;">{{ $other }}</span></div></td>
                        <td>{{ optional($session->subject)->name ?? '—' }}</td>
                        <td class="dim">{{ $session->starts_at->format('M j, Y') }}</td>
                        <td class="dim t-mono">{{ $session->duration_minutes }} min</td>
                        <td><span class="status-badge badge-{{ $session->status }}">{{ str_replace('_', ' ', $session->status) }}</span></td>
                        <td style="text-align:right;" class="t-mono">{{ \App\Support\Money::format($session->amount(), $session->currency) }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="6" style="text-align:center;padding:2.5rem;color:#a1a1aa;">No sessions yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>

</x-app-layout>
