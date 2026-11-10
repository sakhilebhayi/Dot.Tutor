<x-app-layout>
<div class="t-page">
    <h1 class="t-h1">Find a tutor</h1>
    <p class="t-lede">Say what you need. We rank tutors on how well they fit it and tell you why. Nobody pays to be placed higher.</p>

    @if($errors->any())
        <div class="t-flash" role="alert">{{ $errors->first() }}</div>
    @endif

    <form method="GET" action="{{ route('marketplace.find') }}" class="t-panel">
        <div class="t-grid2">
            <div class="t-row">
                <label class="t-label" for="subject_id">Subject</label>
                <select class="t-field" id="subject_id" name="subject_id" required>
                    <option value="">Choose a subject</option>
                    @foreach($subjects as $subject)
                        <option value="{{ $subject->id }}" @selected((int) request('subject_id') === $subject->id)>{{ $subject->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="t-row">
                <label class="t-label" for="help_type">Kind of help</label>
                <select class="t-field" id="help_type" name="help_type">
                    <option value="">Any</option>
                    @foreach($helpTypes as $type)
                        <option value="{{ $type->value }}" @selected(request('help_type') === $type->value)>{{ $type->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div class="t-row">
                <label class="t-label" for="format">How</label>
                <select class="t-field" id="format" name="format">
                    <option value="either" @selected(request('format', 'either') === 'either')>Online or in person</option>
                    <option value="online" @selected(request('format') === 'online')>Online</option>
                    <option value="in_person" @selected(request('format') === 'in_person')>In person</option>
                </select>
            </div>
            <div class="t-row">
                <label class="t-label" for="area">Area (for in person)</label>
                <input class="t-field" id="area" name="area" value="{{ request('area') }}" maxlength="120" autocomplete="address-level2">
            </div>
            <div class="t-row">
                <label class="t-label" for="languages">Teaching languages, in order (codes, e.g. zu, en)</label>
                <input class="t-field" id="languages" name="languages" value="{{ is_array(request('languages')) ? implode(', ', request('languages')) : request('languages') }}" maxlength="60">
            </div>
            <div class="t-row">
                <label class="t-label" for="budget_rand">Budget per hour ({{ config('tutor.currency') }})</label>
                <input class="t-field t-mono" id="budget_rand" name="budget_rand" inputmode="decimal" value="{{ request('budget_rand') }}">
            </div>
        </div>
        <button class="t-btn t-btn-primary" type="submit">Show matches</button>
    </form>

    @if($result)
        <div class="t-panel" aria-live="polite">
            <h2 class="t-h2">{{ count($result['results']) }} {{ Str::plural('match', count($result['results'])) }}</h2>
            <p class="t-hint">{{ $result['considered'] }} {{ Str::plural('tutor', $result['considered']) }} {{ $result['considered'] === 1 ? 'teaches' : 'teach' }} this subject; {{ $result['excluded'] }} did not fit a hard requirement (format, area, language, budget, level or kind of help).</p>
            @foreach($result['notes'] as $note)<p class="t-hint">{{ $note }}</p>@endforeach

            @forelse($result['results'] as $match)
                <article style="padding:1rem 0;border-top:1px solid rgba(255,255,255,0.08);">
                    <h3 class="t-h2"><a href="{{ route('marketplace.tutors.show', $match['tutor']['id']) }}" style="color:#f4f4f5;">{{ $match['tutor']['name'] }}</a></h3>
                    <p style="margin:.2rem 0 .5rem;font-size:.82rem;color:#d4d4d8;">{{ $match['tutor']['headline'] }}</p>
                    <p style="margin:0 0 .5rem;font-size:.78rem;color:#a1a1aa;">
                        <span class="t-state">{{ $match['tutor']['verification']['label'] }}</span>
                        @if($match['price']) <span class="t-mono">{{ $match['price']['display'] }}</span> @endif
                        <span class="t-mono">Fit {{ number_format($match['score'], 0) }} / 100</span>
                    </p>
                    <ul style="margin:0;padding-left:1.1rem;font-size:.8rem;color:#d4d4d8;line-height:1.6;max-width:70ch;">
                        @foreach($match['reasons'] as $reason)<li>{{ $reason }}</li>@endforeach
                    </ul>
                </article>
            @empty
                <p class="t-hint">No tutor fits all of that yet. Try widening the budget, the format or the languages.</p>
            @endforelse
        </div>
    @endif
</div>
</x-app-layout>
