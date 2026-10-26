<x-app-layout>

<style>
    .panel {
        background:#141416;
        border: 1px solid rgba(255,255,255,0.07);
        border-radius: 12px;
        padding: 1.75rem;
        max-width: 640px;
    }
    .status-badge {
        display: inline-flex; align-items: center; gap: 0.3rem;
        padding: 0.25rem 0.7rem; border-radius: 9999px;
        font-size: 0.68rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em;
    }
    .badge-pending    { background: rgba(234,179,8,0.12);  color: #fbbf24; }
    .badge-confirmed  { background: rgba(34,197,94,0.12);  color: #4ade80; }
    .badge-completed  { background: rgba(99,102,241,0.15); color: #a5b4fc; }
    .badge-cancelled  { background: rgba(239,68,68,0.12);  color: #f87171; }
    .badge-no_show    { background: rgba(161,161,170,0.15); color: #a1a1aa; }
    .confirm-btn {
        margin-top: 1.25rem; margin-right: 0.6rem; padding: 0.6rem 1.1rem; border-radius: 9999px;
        background: rgba(34,197,94,0.12); color: #4ade80; border: 1px solid rgba(34,197,94,0.25);
        font-size: 0.78rem; font-weight: 700; cursor: pointer;
    }
    .complete-btn {
        margin-top: 1.25rem; margin-right: 0.6rem; padding: 0.6rem 1.1rem; border-radius: 9999px;
        background: rgba(99,102,241,0.15); color: #a5b4fc; border: 1px solid rgba(99,102,241,0.3);
        font-size: 0.78rem; font-weight: 700; cursor: pointer;
    }
    .detail-row { display: flex; justify-content: space-between; padding: 0.6rem 0; border-bottom: 1px solid rgba(67,70,86,0.15); font-size: 0.82rem; }
    .detail-row:last-child { border-bottom: none; }
    .detail-label { color: #71717a; }
    .detail-value { color: #f4f4f5; font-weight: 600; }
    .cancel-btn {
        margin-top: 1.25rem; padding: 0.6rem 1.1rem; border-radius: 9999px;
        background: rgba(239,68,68,0.12); color: #f87171; border: 1px solid rgba(239,68,68,0.25);
        font-size: 0.78rem; font-weight: 700; cursor: pointer;
    }
    .field-input {
        width: 100%; background: #0f0f11; border: 1px solid rgba(67,70,86,0.4);
        border-radius: 8px; padding: 0.6rem 0.85rem; font-size: 0.85rem; color: #f4f4f5;
    }
    .error-text { color: #f87171; font-size: 0.72rem; margin-top: 0.3rem; }
    .resource-row {
        display: flex; align-items: center; justify-content: space-between;
        padding: 0.6rem 0; border-bottom: 1px solid rgba(67,70,86,0.15); font-size: 0.82rem;
    }
    .resource-row:last-child { border-bottom: none; }
</style>

<div style="padding: 2rem 2.5rem;">

    <a href="{{ route('dashboard') }}" style="font-size:0.78rem;color:#71717a;text-decoration:none;display:inline-block;margin-bottom:1rem;">
        &larr; Back to dashboard
    </a>

    @if(session('status'))
    <div style="max-width:640px;margin-bottom:1rem;padding:0.75rem 1rem;border-radius:8px;background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.25);color:#4ade80;font-size:0.82rem;">
        {{ session('status') }}
    </div>
    @endif

    <div class="panel">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;">
            <div style="font-family:'Syne',sans-serif;font-size:1.1rem;font-weight:800;color:#f4f4f5;">
                {{ optional($session->subject)->name ?? 'Session' }}
            </div>
            <span class="status-badge badge-{{ $session->status }}">{{ $session->status }}</span>
        </div>

        <div class="detail-row">
            <span class="detail-label">Tutor</span>
            <span class="detail-value">{{ optional($session->tutorProfile->user)->name ?? 'Deleted account' }}</span>
        </div>
        <div class="detail-row">
            <span class="detail-label">Student</span>
            <span class="detail-value">{{ optional($session->student)->name ?? 'Deleted account' }}</span>
        </div>
        <div class="detail-row">
            <span class="detail-label">When</span>
            <span class="detail-value">{{ $session->starts_at->format('l, M j, Y \a\t g:i A') }}</span>
        </div>
        <div class="detail-row">
            <span class="detail-label">Duration</span>
            <span class="detail-value">{{ $session->duration_minutes }} minutes</span>
        </div>
        <div class="detail-row">
            <span class="detail-label">Delivery</span>
            <span class="detail-value">{{ ucfirst(str_replace('_',' ',$session->delivery)) }}</span>
        </div>
        <div class="detail-row">
            <span class="detail-label">Agreed value</span>
            <span class="detail-value t-mono">{{ \App\Support\Money::format($session->amount(), $session->currency) }}</span>
        </div>
        <div class="detail-row">
            <span class="detail-label">Payment</span>
            <span class="detail-value" style="font-weight:500;color:#d4d4d8;text-align:right;max-width:22rem;">Payment is arranged outside the platform for now. This is the rate agreed when the session was booked ({{ \App\Support\Money::format($session->rate, $session->currency) }}/hr); nothing is charged here.</span>
        </div>

        @if($session->notes)
        <div style="margin-top:1rem;">
            <div class="detail-label" style="margin-bottom:0.3rem;">Notes</div>
            <div style="font-size:0.82rem;color:#a1a1aa;">{{ $session->notes }}</div>
        </div>
        @endif

        <div style="display:flex;flex-wrap:wrap;">
        @can('confirm', $session)
        <form method="POST" action="{{ route('sessions.confirm', $session) }}">
            @csrf
            <button type="submit" class="confirm-btn">Confirm session</button>
        </form>
        @endcan

        @can('complete', $session)
        <form method="POST" action="{{ route('sessions.complete', $session) }}">
            @csrf
            <button type="submit" class="complete-btn">Mark completed</button>
        </form>
        @endcan

        @if($session->isOpen())
        <form method="POST" action="{{ route('sessions.cancel', $session) }}" onsubmit="return confirm('Cancel this session?');">
            @csrf
            <button type="submit" class="cancel-btn">Cancel session</button>
        </form>
        @endif
        </div>

        @if($session->rating)
        <div style="margin-top:1.5rem;padding-top:1.25rem;border-top:1px solid rgba(67,70,86,0.15);">
            <div class="detail-label" style="margin-bottom:0.4rem;">Your review</div>
            <div style="color:#fbbf24;font-size:0.9rem;margin-bottom:0.3rem;">
                {{ str_repeat('★', $session->rating->rating) }}{{ str_repeat('☆', 5 - $session->rating->rating) }}
            </div>
            @if($session->rating->review)
            <p style="font-size:0.82rem;color:#a1a1aa;">{{ $session->rating->review }}</p>
            @endif
        </div>
        @endif

        @can('rate', $session)
        <div style="margin-top:1.5rem;padding-top:1.25rem;border-top:1px solid rgba(67,70,86,0.15);">
            <div class="detail-label" style="margin-bottom:0.6rem;">Rate this session</div>
            <form method="POST" action="{{ route('sessions.rating.store', $session) }}">
                @csrf
                <div style="margin-bottom:0.75rem;">
                    <select aria-label="Rating" name="rating" class="field-input" style="width:auto;">
                        <option value="">Select a rating…</option>
                        @for($i = 5; $i >= 1; $i--)
                            <option value="{{ $i }}" @selected(old('rating') == $i)>{{ $i }} star{{ $i > 1 ? 's' : '' }}</option>
                        @endfor
                    </select>
                    @error('rating')<div class="error-text">{{ $message }}</div>@enderror
                </div>
                <div style="margin-bottom:0.75rem;">
                    <textarea aria-label="Optional review" name="review" rows="3" placeholder="Optional review…" class="field-input">{{ old('review') }}</textarea>
                    @error('review')<div class="error-text">{{ $message }}</div>@enderror
                </div>
                <button type="submit" class="confirm-btn">Submit review</button>
            </form>
        </div>
        @endcan

        <div style="margin-top:1.5rem;padding-top:1.25rem;border-top:1px solid rgba(67,70,86,0.15);">
            <div class="detail-label" style="margin-bottom:0.6rem;">Lesson resources</div>

            @forelse($session->resources as $resource)
            <div class="resource-row">
                <div>
                    <div style="color:#f4f4f5;font-weight:600;">{{ $resource->title }}</div>
                    <div style="color:#71717a;font-size:0.72rem;">
                        Uploaded by {{ optional($resource->uploader)->name ?? 'a deleted account' }}
                        &middot; {{ $resource->created_at->format('M j, Y') }}
                    </div>
                </div>
                <div style="display:flex;gap:0.5rem;flex-shrink:0;">
                    <a href="{{ route('resources.download', $resource) }}" style="color:#a5b4fc;text-decoration:none;font-size:0.78rem;font-weight:700;">Download</a>
                    @if($resource->uploader_id === auth()->id())
                    <form method="POST" action="{{ route('resources.destroy', $resource) }}" onsubmit="return confirm('Remove this resource?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" style="background:none;border:none;color:#f87171;font-size:0.78rem;font-weight:700;cursor:pointer;padding:0;">Remove</button>
                    </form>
                    @endif
                </div>
            </div>
            @empty
            <p style="font-size:0.8rem;color:#71717a;">No resources shared yet.</p>
            @endforelse

            @can('uploadResource', $session)
            <form method="POST" action="{{ route('resources.store', $session) }}" enctype="multipart/form-data" style="margin-top:1rem;">
                @csrf
                <div style="margin-bottom:0.75rem;">
                    <input aria-label="Title" type="text" name="title" placeholder="Title (e.g. Practice worksheet)" class="field-input" value="{{ old('title') }}">
                    @error('title')<div class="error-text">{{ $message }}</div>@enderror
                </div>
                <div style="margin-bottom:0.75rem;">
                    <input aria-label="File" type="file" name="file" class="field-input">
                    @error('file')<div class="error-text">{{ $message }}</div>@enderror
                </div>
                <button type="submit" class="complete-btn">Upload resource</button>
            </form>
            @endcan
        </div>
    </div>

</div>

</x-app-layout>
