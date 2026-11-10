{{-- Wave-1 functional frame for safety screens, inside the incumbent dark shell. Wave 2 replaces it with the ds design system. --}}
<style>
    .sf { padding: 2rem clamp(1rem, 4vw, 2.5rem); max-width: 860px; color: #e4e4e7; font-size: .9rem; line-height: 1.55; }
    .sf h1 { font-size: 1.45rem; font-weight: 700; color: #fafafa; margin: 0 0 .35rem; text-wrap: balance; }
    .sf h2 { font-size: 1.02rem; font-weight: 700; color: #fafafa; margin: 2rem 0 .6rem; }
    .sf h3 { font-size: .92rem; font-weight: 700; color: #fafafa; margin: 1.25rem 0 .4rem; }
    .sf p { max-width: 68ch; margin: 0 0 .75rem; }
    .sf .lede { color: #a1a1aa; }
    .sf .panel { background: #141416; border: 1px solid rgba(255,255,255,.08); border-radius: 12px; padding: 1.25rem 1.4rem; margin-top: 1rem; }
    .sf .row { display: flex; flex-wrap: wrap; gap: .4rem 1rem; justify-content: space-between; padding: .6rem 0; border-bottom: 1px solid rgba(255,255,255,.06); }
    .sf .row:last-child { border-bottom: 0; }
    .sf .muted { color: #a1a1aa; font-size: .82rem; }
    .sf .tag { display: inline-block; padding: .1rem .55rem; border-radius: 999px; font-size: .74rem; font-weight: 600; background: rgba(255,255,255,.08); color: #e4e4e7; }
    .sf .tag-critical, .sf .tag-declined, .sf .tag-suspended, .sf .tag-limited { background: rgba(239,68,68,.16); color: #fca5a5; }
    .sf .tag-high, .sf .tag-pending, .sf .tag-escalated, .sf .tag-open { background: rgba(234,179,8,.16); color: #fcd34d; }
    .sf .tag-approved, .sf .tag-active, .sf .tag-closed, .sf .tag-actioned { background: rgba(34,197,94,.14); color: #86efac; }
    .sf label { display: block; font-weight: 600; margin: .9rem 0 .3rem; color: #f4f4f5; }
    .sf input[type=text], .sf input[type=email], .sf input[type=date], .sf input[type=number], .sf select, .sf textarea {
        width: 100%; max-width: 32rem; background: #0f0f11; color: #f4f4f5; border: 1px solid rgba(161,161,170,.35);
        border-radius: 8px; padding: .55rem .75rem; font: inherit; }
    .sf textarea { max-width: 100%; min-height: 5.5rem; }
    .sf .check { display: flex; gap: .6rem; align-items: flex-start; font-weight: 400; }
    .sf .check input { margin-top: .3rem; accent-color: #a5b4fc; }
    .sf button { margin-top: 1rem; padding: .55rem 1.1rem; border-radius: 999px; font: inherit; font-weight: 700; font-size: .84rem; cursor: pointer;
        background: #e4e4e7; color: #111113; border: 1px solid transparent; }
    .sf button.secondary { background: transparent; color: #e4e4e7; border-color: rgba(161,161,170,.45); }
    .sf button:hover { filter: brightness(1.08); }
    .sf :focus-visible { outline: 2px solid #a5b4fc; outline-offset: 2px; }
    .sf a { color: #c7d2fe; text-underline-offset: 3px; }
    .sf .notice { padding: .75rem 1rem; border-radius: 10px; background: rgba(99,102,241,.12); color: #e0e7ff; margin: 1rem 0; }
    .sf .ok { padding: .75rem 1rem; border-radius: 10px; background: rgba(34,197,94,.12); color: #bbf7d0; margin: 1rem 0; }
    .sf .err { padding: .75rem 1rem; border-radius: 10px; background: rgba(239,68,68,.14); color: #fecaca; margin: 1rem 0; }
    .sf .consent { white-space: pre-line; background: #0f0f11; border-radius: 10px; padding: 1rem; border: 1px solid rgba(255,255,255,.08); }
    .sf .msg { padding: .7rem 0; border-bottom: 1px solid rgba(255,255,255,.06); }
    .sf .msg-body { white-space: pre-wrap; overflow-wrap: anywhere; }
    .sf table { width: 100%; border-collapse: collapse; font-size: .84rem; font-variant-numeric: tabular-nums; }
    .sf th, .sf td { text-align: left; padding: .5rem .4rem; border-bottom: 1px solid rgba(255,255,255,.06); vertical-align: top; }
    .sf th { color: #a1a1aa; font-weight: 600; }
    .sf .scroll { overflow-x: auto; }
    .sf ::selection { background: rgba(165,180,252,.35); }
</style>
<div class="sf">
    @if (session('status'))<div class="ok" role="status">{{ session('status') }}</div>@endif
    @if ($errors->any())
        <div class="err" role="alert">@foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>
    @endif
    {{ $slot }}
</div>
