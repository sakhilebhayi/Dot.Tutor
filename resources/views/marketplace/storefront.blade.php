<x-app-layout>
<div class="t-page">
    <h1 class="t-h1">{{ $tutor['name'] }}</h1>
    <p class="t-lede">{{ $tutor['headline'] }}</p>

    @if($errors->any())<div class="t-flash" role="alert">{{ $errors->first() }}</div>@endif

    <section class="t-panel" aria-labelledby="verification-h">
        <h2 class="t-h2" id="verification-h">{{ $tutor['verification']['label'] }}</h2>
        <p class="t-hint" style="margin:0;">{{ $tutor['verification']['description'] }}</p>
    </section>

    <section class="t-panel" aria-labelledby="about-h">
        <h2 class="t-h2" id="about-h">About</h2>
        @if($tutor['introduction'])<p style="font-size:.85rem;color:#d4d4d8;max-width:70ch;white-space:pre-line;">{{ $tutor['introduction'] }}</p>@endif
        @if($tutor['teaching_approach'])<p style="font-size:.85rem;color:#d4d4d8;max-width:70ch;"><strong>How I teach:</strong> {{ $tutor['teaching_approach'] }}</p>@endif
        <p class="t-hint" style="margin:0;">{{ $tutor['experience']['sessions_completed'] }} lessons completed on Dot.Tutor.
            {{ $tutor['reviews']['count'] === 0 ? 'No reviews yet.' : $tutor['reviews']['count'].' published '.Str::plural('review', $tutor['reviews']['count']).', average '.number_format($tutor['reviews']['average'], 1).' out of 5.' }}</p>
    </section>

    <section class="t-panel" aria-labelledby="quals-h">
        <h2 class="t-h2" id="quals-h">Qualifications</h2>
        @forelse($tutor['qualifications'] as $q)
            <p style="font-size:.85rem;color:#d4d4d8;margin:.3rem 0;">{{ $q['title'] }}@if($q['institution']), {{ $q['institution'] }}@endif
                <span class="t-state {{ $q['verified'] ? 't-state-approved' : '' }}">{{ $q['status_label'] }}</span></p>
        @empty
            <p class="t-hint" style="margin:0;">No qualification records yet.</p>
        @endforelse
        @if($tutor['self_declared_qualifications'])
            <p style="font-size:.8rem;color:#a1a1aa;margin-top:.75rem;white-space:pre-line;"><strong>{{ $tutor['self_declared_qualifications']['label'] }}:</strong> {{ $tutor['self_declared_qualifications']['text'] }}</p>
        @endif
    </section>

    <section class="t-panel" aria-labelledby="offer-h">
        <h2 class="t-h2" id="offer-h">Lessons and prices</h2>
        <p class="t-hint">Times are in {{ $tutor['timezone'] }}. A request holds the time until the tutor answers.</p>
        @forelse($tutor['offerings'] as $o)
            <div style="padding:.9rem 0;border-top:1px solid rgba(255,255,255,0.08);">
                <p style="margin:0;font-size:.9rem;color:#f4f4f5;font-weight:700;">{{ $o['title'] }} <span class="t-state">{{ $o['type_label'] }}</span></p>
                <p class="t-mono" style="margin:.2rem 0;font-size:.8rem;color:#fcd34d;">{{ $o['price_display'] }} &middot; {{ $o['duration_minutes'] }} min @if($o['package_lessons']) &middot; {{ $o['package_lessons'] }} lessons @endif</p>
                <p class="t-hint" style="margin:0 0 .5rem;">{{ $o['cancellation']['summary'] }}</p>
                @if(in_array($o['type'], ['one_to_one', 'consultation', 'homework_help', 'exam_prep', 'revision'], true))
                    <form method="POST" action="{{ route('marketplace.book', [$profile, $o['id']]) }}" class="t-grid2">
                        @csrf
                        <div>
                            <label class="t-label" for="subject-{{ $o['id'] }}">Subject</label>
                            <select class="t-field" id="subject-{{ $o['id'] }}" name="subject_id" required>
                                @foreach($o['subjects'] as $s)<option value="{{ $s['id'] }}">{{ $s['name'] }}</option>@endforeach
                            </select>
                        </div>
                        <div>
                            <label class="t-label" for="start-{{ $o['id'] }}">Start ({{ $tutor['timezone'] }})</label>
                            <input class="t-field" type="datetime-local" id="start-{{ $o['id'] }}" name="starts_at" required>
                        </div>
                        @if($o['format'] === 'either')
                            <div>
                                <label class="t-label" for="delivery-{{ $o['id'] }}">How</label>
                                <select class="t-field" id="delivery-{{ $o['id'] }}" name="delivery"><option value="online">Online</option><option value="in_person">In person</option></select>
                            </div>
                        @endif
                        <div><button class="t-btn t-btn-primary" type="submit">Request this lesson</button></div>
                    </form>
                @else
                    <p class="t-hint" style="margin:0;">Booked through the Dot.Tutor app (group seats, packages and weekly series).</p>
                @endif
            </div>
        @empty
            <p class="t-hint" style="margin:0;">No lessons published yet.</p>
        @endforelse
    </section>
</div>
</x-app-layout>
