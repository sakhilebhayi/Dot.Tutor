<x-app-layout>
<div class="t-page">
    <h1 class="t-h1">My bookings</h1>
    @if(session('status'))<div class="t-flash" role="status">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="t-flash" role="alert">{{ $errors->first() }}</div>@endif

    @foreach(['Lessons I booked' => $asLearner, 'Requests to me as a tutor' => $asTutor] as $title => $rows)
        @if($rows->isNotEmpty() || $loop->first)
        <section class="t-panel" aria-label="{{ $title }}">
            <h2 class="t-h2">{{ $title }}</h2>
            @forelse($rows as $b)
                <div style="padding:.85rem 0;border-top:1px solid rgba(255,255,255,0.08);">
                    <p style="margin:0;font-size:.88rem;color:#f4f4f5;">{{ $b['subject']['name'] }} with {{ $b['tutor']['name'] }}
                        <span class="t-state">{{ $b['state_label'] }}</span></p>
                    <p class="t-mono" style="margin:.2rem 0;font-size:.78rem;color:#a1a1aa;">{{ \Illuminate\Support\Carbon::parse($b['starts_at'])->setTimezone($b['timezone'])->format('D j M Y, H:i') }} ({{ $b['timezone'] }}) &middot; {{ $b['duration_minutes'] }} min &middot; {{ $b['price']['display'] }}</p>
                    <p class="t-hint" style="margin:0 0 .5rem;">{{ $b['next_step'] }}</p>
                    <div style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:flex-end;">
                        @if($title !== 'Lessons I booked' && $b['state'] === 'requested')
                            <form method="POST" action="{{ route('marketplace.bookings.act', [$b['id'], 'accept']) }}">@csrf<button class="t-btn t-btn-primary" type="submit">Accept</button></form>
                            <form method="POST" action="{{ route('marketplace.bookings.act', [$b['id'], 'decline']) }}" style="display:flex;gap:.5rem;align-items:flex-end;">@csrf
                                <div><label class="t-label" for="reason-{{ $b['id'] }}">Reason for declining</label><input class="t-field" id="reason-{{ $b['id'] }}" name="reason" required minlength="3" maxlength="1000"></div>
                                <button class="t-btn t-btn-quiet" type="submit">Decline</button></form>
                        @endif
                        @if(in_array($b['state'], ['requested', 'awaiting_guardian', 'awaiting_payment', 'confirmed'], true) && $b['kind'] === 'one_to_one')
                            <form method="POST" action="{{ route('marketplace.bookings.act', [$b['id'], 'cancel']) }}">@csrf<button class="t-btn t-btn-danger" type="submit">Cancel booking</button></form>
                        @endif
                    </div>
                </div>
            @empty
                <p class="t-hint" style="margin:0;">Nothing yet. <a href="{{ route('marketplace.find') }}" style="color:#fbbf24;">Find a tutor</a>.</p>
            @endforelse
        </section>
        @endif
    @endforeach
</div>
</x-app-layout>
