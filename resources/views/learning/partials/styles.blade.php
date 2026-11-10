{{-- Wave-1 functional styling for the learning screens, inside the existing signed-in shell. Wave 2 replaces it with the design system. --}}
<style>
    .ln { max-width: 72rem; margin: 0 auto; padding: 1.5rem 1rem 4rem; color: #e4e4e7; }
    .ln h1 { font-family: 'Syne', sans-serif; font-size: clamp(1.4rem, 2.6vw, 1.9rem); line-height: 1.15; margin: 0 0 .5rem; color: #fafafa; text-wrap: balance; }
    .ln h2 { font-family: 'Syne', sans-serif; font-size: 1.1rem; margin: 2rem 0 .75rem; color: #fafafa; }
    .ln h3 { font-size: .95rem; margin: 1.25rem 0 .5rem; color: #fafafa; }
    .ln p, .ln li { max-width: 70ch; }
    .ln .muted { color: #a1a1aa; }
    .ln .grid { display: grid; gap: 1.5rem; }
    @media (min-width: 900px) { .ln .grid.two { grid-template-columns: minmax(0, 2fr) minmax(0, 1fr); } }
    .ln .panel { background: #121215; border: 1px solid #27272a; border-radius: 10px; padding: 1.25rem; }
    .ln .row { display: flex; flex-wrap: wrap; gap: .5rem 1rem; align-items: center; }
    .ln label { display: block; font-size: .8rem; font-weight: 600; color: #d4d4d8; margin: .9rem 0 .3rem; }
    .ln input[type=text], .ln input[type=number], .ln input[type=url], .ln input[type=search], .ln input[type=file], .ln select, .ln textarea {
        width: 100%; background: #0b0b0d; color: #f4f4f5; border: 1px solid #3f3f46; border-radius: 8px; padding: .55rem .7rem; font: inherit; font-size: .9rem; }
    .ln textarea { min-height: 7rem; }
    .ln input:focus-visible, .ln select:focus-visible, .ln textarea:focus-visible, .ln a:focus-visible, .ln button:focus-visible, .ln summary:focus-visible { outline: 2px solid #fbbf24; outline-offset: 2px; }
    .ln .hint { font-size: .78rem; color: #a1a1aa; margin-top: .25rem; }
    .ln .btn { display: inline-flex; align-items: center; gap: .4rem; padding: .55rem 1rem; border-radius: 8px; border: 1px solid #fbbf24; background: #fbbf24; color: #09090b; font-weight: 700; font-size: .85rem; cursor: pointer; text-decoration: none; }
    .ln .btn.ghost { background: transparent; color: #fde68a; border-color: #52525b; }
    .ln .btn.danger { background: transparent; color: #fca5a5; border-color: #7f1d1d; }
    .ln .btn:hover { filter: brightness(1.08); }
    .ln a { color: #fcd34d; text-underline-offset: 3px; }
    .ln .tag { display: inline-block; font-size: .72rem; font-weight: 600; padding: .1rem .5rem; border-radius: 999px; border: 1px solid #3f3f46; color: #d4d4d8; }
    .ln .tag.ok { border-color: #166534; color: #86efac; }
    .ln .tag.warn { border-color: #854d0e; color: #fcd34d; }
    .ln .notice { padding: .75rem 1rem; border-radius: 8px; border: 1px solid #3f3f46; background: #18181b; margin: .75rem 0; }
    .ln .notice.error { border-color: #7f1d1d; background: #1c0f0f; color: #fecaca; }
    .ln .notice.success { border-color: #14532d; background: #0d1a12; color: #bbf7d0; }
    .ln ul.plain { list-style: none; padding: 0; margin: 0; }
    .ln ul.plain > li { padding: .65rem 0; border-top: 1px solid #27272a; max-width: none; }
    .ln .prose { line-height: 1.65; font-size: 1rem; }
    .ln .prose code { background: #18181b; padding: .05rem .3rem; border-radius: 4px; }
    .ln table { width: 100%; border-collapse: collapse; font-size: .85rem; font-variant-numeric: tabular-nums; }
    .ln th, .ln td { text-align: left; padding: .5rem .4rem; border-top: 1px solid #27272a; vertical-align: top; }
    .ln fieldset { border: 1px solid #27272a; border-radius: 8px; padding: .75rem 1rem 1rem; margin: 0 0 1rem; }
    .ln legend { padding: 0 .4rem; font-weight: 600; color: #fafafa; }
    .ln .sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
    .ln ::selection { background: #fbbf24; color: #09090b; }
</style>
