<x-app-layout>

<style>
    .panel {
        background:#141416;
        border: 1px solid rgba(255,255,255,0.07);
        border-radius: 12px;
        padding: 1.75rem;
    }
    .avatar-xl {
        width: 64px; height: 64px; border-radius: 9999px;
        background: rgba(251,191,36,0.16); border: 1px solid rgba(251,191,36,0.4); color: #fbbf24; display: flex; align-items: center; justify-content: center;
        font-size: 1.4rem; font-weight: 700; flex-shrink: 0;
    }
    .field-label {
        font-size: 0.7rem; font-weight: 700; color: #71717a;
        text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 0.4rem; display: block;
    }
    .field-input {
        width: 100%; background: #0f0f11; border: 1px solid rgba(67,70,86,0.4);
        border-radius: 8px; padding: 0.6rem 0.85rem; font-size: 0.85rem; color: #f4f4f5;
    }
    .subject-pill {
        display: inline-block; font-size: 0.65rem; font-weight: 600;
        color: #71717a; background: rgba(67,70,86,0.25);
        border-radius: 9999px; padding: 0.15rem 0.55rem; margin: 0 0.3rem 0.3rem 0;
    }
    .submit-btn {
        display:inline-flex;align-items:center;justify-content:center;gap:0.5rem;
        padding:0.7rem 1.25rem;background:#fbbf24;
        border-radius:8px;font-family:'Syne',sans-serif;font-size:0.8rem;font-weight:700;
        color:#09090b;border:none;cursor:pointer;width:100%;
    }
    .filter-chip {
        display:inline-flex;align-items:center;padding:0.35rem 0.8rem;border-radius:8px;
        font-size:0.75rem;font-weight:600;border:1px solid rgba(255,255,255,0.16);
        color:#d4d4d8;text-decoration:none;
    }
    .filter-chip:hover { border-color:rgba(251,191,36,0.6); }
    .filter-chip.active { background:#fbbf24;border-color:#fbbf24;color:#09090b; }
    .error-text { color: #f87171; font-size: 0.72rem; margin-top: 0.3rem; }
    @media (max-width: 900px) { .show-grid { grid-template-columns: 1fr !important; } }
</style>

<div style="padding: 2rem 2.5rem; max-width: 1000px;">

    <a href="{{ route('tutors.browse') }}" style="font-size:0.78rem;color:#71717a;text-decoration:none;display:inline-block;margin-bottom:1rem;">
        &larr; Back to tutors
    </a>

    <div class="show-grid" style="display:grid;grid-template-columns:1.2fr 1fr;gap:1.25rem;align-items:start;">

        <div class="panel">
            <div style="display:flex;align-items:center;gap:1rem;margin-bottom:1rem;">
                <div class="avatar-xl">{{ strtoupper(substr(optional($tutorProfile->user)->name ?? '?', 0, 1)) }}</div>
                <div>
                    <div style="font-family:'Syne',sans-serif;font-size:1.15rem;font-weight:800;color:#f4f4f5;">
                        {{ optional($tutorProfile->user)->name ?? 'Unnamed tutor' }}
                    </div>
                    @if($tutorProfile->headline)
                    <div style="font-size:0.85rem;color:#d4d4d8;margin-top:0.1rem;">{{ $tutorProfile->headline }}</div>
                    @endif
                    <div style="font-size:0.85rem;color:#fbbf24;font-weight:700;" class="t-mono">
                        {{ \App\Support\Money::format($tutorProfile->hourly_rate, $tutorProfile->currency) }}/hr &middot; {{ number_format($tutorProfile->rating, 1) }}&#9733; &middot; {{ $tutorProfile->total_sessions }} sessions taught
                    </div>
                </div>
            </div>

            @if($tutorProfile->bio)
            <p style="font-size:0.85rem;color:#a1a1aa;line-height:1.6;margin-bottom:1rem;">
                {{ $tutorProfile->bio }}
            </p>
            @endif

            @if($tutorProfile->qualifications)
            <p style="font-size:0.8rem;color:#a1a1aa;line-height:1.6;margin-bottom:1rem;"><strong style="color:#d4d4d8;">Qualifications:</strong> {{ $tutorProfile->qualifications }}</p>
            @endif

            <div>
                @foreach($tutorProfile->subjects as $subject)
                <span class="subject-pill">{{ $subject->name }} &middot; {{ ucfirst(str_replace('_',' ',$subject->level)) }}</span>
                @endforeach
            </div>
        </div>

        <div class="panel">
            <div style="font-family:'Syne',sans-serif;font-size:0.95rem;font-weight:700;color:#f4f4f5;margin-bottom:0.25rem;">
                Book a session
            </div>
            <p style="font-size:0.72rem;color:#a1a1aa;margin:0 0 1rem;">
                Times are shown in {{ $timezone }}, the tutor's timezone.
            </p>

            @if(auth()->id() === $tutorProfile->user_id)
                <p style="font-size:0.8rem;color:#a1a1aa;">This is your own tutor profile. You can't book yourself.</p>
            @elseif($tutorProfile->subjects->isEmpty())
                <p style="font-size:0.8rem;color:#a1a1aa;">This tutor has no subject open for new bookings right now.</p>
            @else
                <div role="group" aria-label="Session length" style="display:flex;gap:0.4rem;margin-bottom:1rem;flex-wrap:wrap;">
                    @foreach($durations as $minutes)
                        <a href="{{ route('tutors.show', [$tutorProfile, 'duration' => $minutes]) }}"
                           class="filter-chip {{ $minutes === $duration ? 'active' : '' }}"
                           @if($minutes === $duration) aria-current="true" @endif>{{ $minutes }} min</a>
                    @endforeach
                </div>

                @if(! $hasSlots)
                    <p style="font-size:0.82rem;color:#d4d4d8;margin:0 0 0.4rem;">
                        {{ optional($tutorProfile->user)->name ?? 'This tutor' }} has no open times in the next {{ $horizonDays }} days for a {{ $duration }}-minute session.
                    </p>
                    <p style="font-size:0.75rem;color:#a1a1aa;margin:0;">Try a shorter session, or check back later.</p>
                @else
                <form method="POST" action="{{ route('tutors.sessions.store', $tutorProfile) }}">
                    @csrf
                    <input type="hidden" name="duration_minutes" value="{{ $duration }}">

                    <div style="margin-bottom:1rem;">
                        <label class="field-label" for="subject_id">Subject</label>
                        <select name="subject_id" id="subject_id" class="field-input">
                            @foreach($tutorProfile->subjects as $subject)
                            <option value="{{ $subject->id }}" @selected(old('subject_id') == $subject->id)>{{ $subject->name }}</option>
                            @endforeach
                        </select>
                        @error('subject_id')<div class="error-text">{{ $message }}</div>@enderror
                    </div>

                    <fieldset style="border:0;padding:0;margin:0 0 1rem;">
                        <legend class="field-label">Pick a start time</legend>
                        <div style="max-height:18rem;overflow-y:auto;padding-right:0.25rem;">
                        @foreach($days as $day)
                            @continue(count($day['slots']) === 0)
                            @php
                                $dayDate = \Illuminate\Support\Carbon::parse($day['date']);
                                $labels = collect($day['slots'])->map(fn ($s) => $s->format('H:i'));
                            @endphp
                            <div style="margin-bottom:0.75rem;">
                                <div style="font-size:0.72rem;color:#a1a1aa;margin-bottom:0.3rem;">{{ $dayDate->format('l j M') }}</div>
                                <div style="display:flex;flex-wrap:wrap;gap:0.35rem;">
                                @foreach($day['slots'] as $slot)
                                    @php $value = $slot->utc()->format('Y-m-d\TH:i:s\Z'); $local = $slot->setTimezone($timezone); @endphp
                                    <label class="t-chip">
                                        <input type="radio" name="starts_at" value="{{ $value }}" required @checked(old('starts_at') === $value)>
                                        <span class="t-mono">{{ $local->format('H:i') }}@if($labels->filter(fn ($l) => $l === $local->format('H:i'))->count() > 1) {{ $local->format('T') }}@endif</span>
                                    </label>
                                @endforeach
                                </div>
                            </div>
                        @endforeach
                        </div>
                        @error('starts_at')<div class="error-text">{{ $message }}</div>@enderror
                    </fieldset>

                    <div style="margin-bottom:1rem;">
                        <label class="field-label" for="delivery">Delivery</label>
                        <select name="delivery" id="delivery" class="field-input">
                            <option value="online" @selected(old('delivery', 'online') == 'online')>Online</option>
                            <option value="in_person" @selected(old('delivery') == 'in_person')>In person</option>
                        </select>
                        @error('delivery')<div class="error-text">{{ $message }}</div>@enderror
                    </div>

                    <div style="margin-bottom:1.25rem;">
                        <label class="field-label" for="notes">Notes (optional)</label>
                        <textarea name="notes" id="notes" rows="3" class="field-input">{{ old('notes') }}</textarea>
                        @error('notes')<div class="error-text">{{ $message }}</div>@enderror
                    </div>

                    <p style="font-size:0.78rem;color:#d4d4d8;margin:0 0 0.35rem;">
                        {{ \App\Support\Money::format($tutorProfile->hourly_rate * $duration / 60, $tutorProfile->currency) }}
                        for {{ $duration }} minutes at {{ \App\Support\Money::format($tutorProfile->hourly_rate, $tutorProfile->currency) }}/hr.
                    </p>
                    <p style="font-size:0.72rem;color:#a1a1aa;margin:0 0 1rem;">
                        Payment is arranged outside the platform for now. Booking records the rate you agreed to; it does not charge you.
                    </p>

                    <button type="submit" class="submit-btn">Request session</button>
                </form>
                @endif
            @endif
        </div>

    </div>

    @if($reviews->isNotEmpty())
    <div class="panel" style="margin-top:1.25rem;">
        <div style="font-family:'Syne',sans-serif;font-size:0.95rem;font-weight:700;color:#f4f4f5;margin-bottom:1rem;">
            Reviews
        </div>
        @foreach($reviews as $review)
        <div style="padding:0.85rem 0;border-bottom:1px solid rgba(67,70,86,0.15);">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.3rem;">
                <span style="font-size:0.82rem;font-weight:700;color:#f4f4f5;">{{ optional($review->rater)->name ?? 'A student' }}</span>
                <span style="color:#fbbf24;font-size:0.8rem;">{{ str_repeat('★', $review->rating) }}{{ str_repeat('☆', 5 - $review->rating) }}</span>
            </div>
            <p style="font-size:0.82rem;color:#a1a1aa;">{{ $review->review }}</p>
        </div>
        @endforeach
    </div>
    @endif

</div>

</x-app-layout>
