{{-- Wave-1 functional frame for safety screens. Its colours were drawn for the old dark shell and read as white on white in the
     light theme (R-47 a11y D-1701): every colour is now a design-system token, so both themes meet WCAG AA. --}}
<style>
    .sf { padding: 2rem clamp(1rem, 4vw, 2.5rem); max-width: 860px; color: var(--td-ink); font-size: .9rem; line-height: 1.55; }
    .sf h1 { font-size: 1.45rem; font-weight: 700; color: var(--td-ink); margin: 0 0 .35rem; text-wrap: balance; }
    .sf h2 { font-size: 1.02rem; font-weight: 700; color: var(--td-ink); margin: 2rem 0 .6rem; }
    .sf h3 { font-size: .92rem; font-weight: 700; color: var(--td-ink); margin: 1.25rem 0 .4rem; }
    .sf p { max-width: 68ch; margin: 0 0 .75rem; }
    .sf .lede { color: var(--td-ink-2); }
    .sf .panel { background: var(--td-surface); border: 1px solid var(--td-line); border-radius: 12px; padding: 1.25rem 1.4rem; margin-top: 1rem; }
    .sf .row { display: flex; flex-wrap: wrap; gap: .4rem 1rem; justify-content: space-between; padding: .6rem 0; border-bottom: 1px solid var(--td-line); }
    .sf .row:last-child { border-bottom: 0; }
    .sf .muted { color: var(--td-ink-2); font-size: .82rem; }
    .sf .tag { display: inline-block; padding: .1rem .55rem; border-radius: 999px; font-size: .74rem; font-weight: 600; background: var(--td-sunken); color: var(--td-ink); }
    .sf .tag-critical, .sf .tag-declined, .sf .tag-suspended, .sf .tag-limited { background: var(--td-danger-soft); color: var(--td-danger-ink); }
    .sf .tag-high, .sf .tag-pending, .sf .tag-escalated, .sf .tag-open { background: var(--td-warning-soft); color: var(--td-warning-ink); }
    .sf .tag-approved, .sf .tag-active, .sf .tag-closed, .sf .tag-actioned { background: var(--td-success-soft); color: var(--td-success-ink); }
    .sf label { display: block; font-weight: 600; margin: .9rem 0 .3rem; color: var(--td-ink); }
    .sf input[type=text], .sf input[type=email], .sf input[type=date], .sf input[type=number], .sf select, .sf textarea {
        width: 100%; max-width: 32rem; background: var(--td-surface); color: var(--td-ink); border: 1px solid var(--td-edge);
        border-radius: 8px; padding: .55rem .75rem; font: inherit; }
    .sf textarea { max-width: 100%; min-height: 5.5rem; }
    .sf .check { display: flex; gap: .6rem; align-items: flex-start; font-weight: 400; }
    .sf .check input { margin-top: .3rem; accent-color: var(--td-primary); }
    .sf button { margin-top: 1rem; padding: .55rem 1.1rem; border-radius: 999px; font: inherit; font-weight: 700; font-size: .84rem; cursor: pointer;
        background: var(--td-primary); color: var(--td-primary-ink); border: 1px solid transparent; min-height: 44px; }
    .sf button.secondary { background: transparent; color: var(--td-ink); border-color: var(--td-edge); }
    .sf button:hover { filter: brightness(1.08); }
    .sf :focus-visible { outline: 2px solid var(--td-focus); outline-offset: 2px; }
    .sf a { color: var(--td-primary); text-decoration: underline; text-underline-offset: 3px; }
    .sf .notice { padding: .75rem 1rem; border-radius: 10px; background: var(--td-info-soft); color: var(--td-info-ink); margin: 1rem 0; }
    .sf .ok { padding: .75rem 1rem; border-radius: 10px; background: var(--td-success-soft); color: var(--td-success-ink); margin: 1rem 0; }
    .sf .err { padding: .75rem 1rem; border-radius: 10px; background: var(--td-danger-soft); color: var(--td-danger-ink); margin: 1rem 0; }
    .sf .consent { white-space: pre-line; background: var(--td-sunken); border-radius: 10px; padding: 1rem; border: 1px solid var(--td-line); }
    .sf .msg { padding: .7rem 0; border-bottom: 1px solid var(--td-line); }
    .sf .msg-body { white-space: pre-wrap; overflow-wrap: anywhere; }
    .sf table { width: 100%; border-collapse: collapse; font-size: .84rem; font-variant-numeric: tabular-nums; }
    .sf th, .sf td { text-align: left; padding: .5rem .4rem; border-bottom: 1px solid var(--td-line); vertical-align: top; }
    .sf th { color: var(--td-ink-2); font-weight: 600; }
    .sf .scroll { overflow-x: auto; }
    .sf ::selection { background: var(--td-primary-soft); }
</style>
<div class="sf">
    @if (session('status'))<div class="ok" role="status">{{ session('status') }}</div>@endif
    @if ($errors->any())
        <div class="err" role="alert">@foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>
    @endif
    {{ $slot }}
</div>
