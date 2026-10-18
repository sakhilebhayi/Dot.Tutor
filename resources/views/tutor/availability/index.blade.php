<x-app-layout>
@php $weekdays = \App\Models\TutorAvailabilityWindow::WEEKDAYS; @endphp
<div class="t-page">
    <h1 class="t-h1">My hours</h1>
    <p class="t-lede">
        The weekly times you are open to teach, written in your timezone
        (<span class="t-mono" style="color:#d4d4d8;">{{ $timezone }}</span>; change it on your
        <a href="{{ route('tutor.profile.edit') }}" style="color:#fbbf24;">profile</a>).
        Students can only book a start time that sits fully inside one of these windows.
    </p>

    @if(session('status'))<div class="t-flash" role="status">{{ session('status') }}</div>@endif

    @unless($profile->isApproved())
        <div class="t-flash" role="note">
            Your profile is {{ $profile->status }}, so students cannot see or book these hours yet.
            You can still set them up now.
        </div>
    @endunless

    <div style="display:grid;grid-template-columns:1.4fr 1fr;gap:1rem;align-items:start;">
        <div class="t-panel">
            <h2 class="t-h2">Weekly windows</h2>
            <p class="t-hint">
                @if($openDaysAhead > 0)
                    {{ $openDaysAhead }} of the next {{ config('tutor.booking_horizon_days') }} days currently have a bookable time.
                @else
                    No day in the next {{ config('tutor.booking_horizon_days') }} has a bookable time yet.
                @endif
            </p>

            <div class="table-scroll" role="region" aria-label="Weekly windows" tabindex="0">
            <table class="t-table">
                <thead><tr><th>Day</th><th>Open</th><th></th></tr></thead>
                <tbody>
                @foreach($weekdays as $number => $name)
                    @forelse($windows->get($number, collect()) as $window)
                    <tr>
                        <td>@if($loop->first){{ $name }}@endif</td>
                        <td class="t-mono">{{ $window->start_time }} &ndash; {{ $window->end_time }}</td>
                        <td style="text-align:right;">
                            <form method="POST" action="{{ route('tutor.availability.windows.destroy', $window->id) }}">
                                @csrf @method('DELETE')
                                <button type="submit" class="t-btn t-btn-quiet" style="padding:.3rem .7rem;" aria-label="Remove {{ $name }} {{ $window->start_time }} to {{ $window->end_time }}">Remove</button>
                            </form>
                        </td>
                    </tr>
                    @empty
                    <tr><td>{{ $name }}</td><td style="color:#71717a;">Closed</td><td></td></tr>
                    @endforelse
                @endforeach
                </tbody>
            </table>
            </div>
        </div>

        <div>
            <form method="POST" action="{{ route('tutor.availability.windows.store') }}" class="t-panel">
                @csrf
                <h2 class="t-h2">Add hours</h2>
                <p class="t-hint">One window per block. For a split day, add two.</p>
                <div class="t-row">
                    <label class="t-label" for="weekday">Day</label>
                    <select class="t-field" id="weekday" name="weekday">
                        @foreach($weekdays as $number => $name)
                            <option value="{{ $number }}" @selected((int) old('weekday', 1) === $number)>{{ $name }}</option>
                        @endforeach
                    </select>
                    @error('weekday')<div class="t-err">{{ $message }}</div>@enderror
                </div>
                <div class="t-grid2 t-row">
                    <div>
                        <label class="t-label" for="start_time">From</label>
                        <input class="t-field t-mono" type="time" id="start_time" name="start_time" value="{{ old('start_time', '09:00') }}" required>
                        @error('start_time')<div class="t-err">{{ $message }}</div>@enderror
                    </div>
                    <div>
                        <label class="t-label" for="end_time">Until</label>
                        <input class="t-field t-mono" type="time" id="end_time" name="end_time" value="{{ old('end_time', '12:00') }}" required>
                        @error('end_time')<div class="t-err">{{ $message }}</div>@enderror
                    </div>
                </div>
                <button type="submit" class="t-btn t-btn-primary">Add hours</button>
            </form>

            <div class="t-panel">
                <h2 class="t-h2">Days off</h2>
                <p class="t-hint">A blocked date has no bookable times, whatever your weekly hours say.</p>

                @forelse($blockedDates as $blocked)
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:.75rem;padding:.4rem 0;border-bottom:1px solid rgba(255,255,255,0.06);">
                        <span style="font-size:.82rem;color:#d4d4d8;">
                            <span class="t-mono">{{ \Illuminate\Support\Carbon::parse($blocked->date)->format('D j M Y') }}</span>
                            @if($blocked->reason)<span style="color:#71717a;"> &middot; {{ $blocked->reason }}</span>@endif
                        </span>
                        <form method="POST" action="{{ route('tutor.availability.blocked.destroy', $blocked->id) }}">
                            @csrf @method('DELETE')
                            <button type="submit" class="t-btn t-btn-quiet" style="padding:.3rem .7rem;">Unblock</button>
                        </form>
                    </div>
                @empty
                    <p style="font-size:.8rem;color:#71717a;margin:0 0 .75rem;">No days off set.</p>
                @endforelse

                <form method="POST" action="{{ route('tutor.availability.blocked.store') }}" style="margin-top:1rem;">
                    @csrf
                    <div class="t-row">
                        <label class="t-label" for="date">Date</label>
                        <input class="t-field t-mono" type="date" id="date" name="date" min="{{ $today }}" value="{{ old('date') }}" required>
                        @error('date')<div class="t-err">{{ $message }}</div>@enderror
                    </div>
                    <div class="t-row">
                        <label class="t-label" for="reason">Note (only you see this)</label>
                        <input class="t-field" id="reason" name="reason" maxlength="255" value="{{ old('reason') }}">
                    </div>
                    <button type="submit" class="t-btn t-btn-quiet">Block date</button>
                </form>
            </div>
        </div>
    </div>
</div>
</x-app-layout>
