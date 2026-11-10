{{-- Polling for new room activity (no websockets on the shared host). Asks a versioned endpoint every
     few seconds while the tab is visible; shows a notice when something changed. Never reloads by itself. --}}
<div id="room-activity" role="status" aria-live="polite" class="hidden">
    <x-td.notice tone="info" title="There is new activity in this room">
        <a class="td-link" href="{{ url()->current().(request()->getQueryString() ? '?'.request()->getQueryString() : '') }}">Show the latest posts</a>
    </x-td.notice>
</div>
<script @if (\Illuminate\Support\Facades\Vite::cspNonce()) nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}" @endif>
(() => {
    const url = @json(route('rooms.activity', $room));
    let etag = @json('"'.$version.'"');
    const every = {{ (int) config('community.poll_seconds') }} * 1000;
    const box = document.getElementById('room-activity');
    const check = async () => {
        if (document.hidden || !box || !box.classList.contains('hidden')) return;
        try {
            const res = await fetch(url, { headers: { 'Accept': 'application/json', 'If-None-Match': etag }, credentials: 'same-origin' });
            if (res.status === 200) {
                const next = res.headers.get('ETag');
                if (next && next !== etag) { box.classList.remove('hidden'); }
                etag = next || etag;
            }
        } catch (e) { /* offline: try again next time */ }
    };
    setInterval(check, every);
})();
</script>
