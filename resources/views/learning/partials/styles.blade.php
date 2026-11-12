{{-- Wave-1 functional styling for the learning studio. Its colours were drawn for the old dark shell (white headings on the
     light page, R-47 a11y D-1701); every colour is now a design-system token, so both themes meet WCAG AA. --}}
<style>
    .ln { max-width: 72rem; margin: 0 auto; padding: 1.5rem 1rem 4rem; color: var(--td-ink); }
    .ln h1 { font-family: var(--td-font-display); font-size: clamp(1.4rem, 2.6vw, 1.9rem); line-height: 1.15; margin: 0 0 .5rem; color: var(--td-ink); text-wrap: balance; }
    .ln h2 { font-family: var(--td-font-display); font-size: 1.1rem; margin: 2rem 0 .75rem; color: var(--td-ink); }
    .ln h3 { font-size: .95rem; margin: 1.25rem 0 .5rem; color: var(--td-ink); }
    .ln p, .ln li { max-width: 70ch; }
    .ln .muted { color: var(--td-ink-2); }
    .ln .grid { display: grid; gap: 1.5rem; }
    @media (min-width: 900px) { .ln .grid.two { grid-template-columns: minmax(0, 2fr) minmax(0, 1fr); } }
    @media (max-width: 899px) { .ln .grid.two > aside.first-on-mobile { order: -1; } }
    .ln .panel { background: var(--td-surface); border: 1px solid var(--td-line); border-radius: 10px; padding: 1.25rem; }
    .ln .row { display: flex; flex-wrap: wrap; gap: .5rem 1rem; align-items: center; }
    .ln label { display: block; font-size: .8rem; font-weight: 600; color: var(--td-ink); margin: .9rem 0 .3rem; }
    .ln input[type=text], .ln input[type=number], .ln input[type=url], .ln input[type=search], .ln input[type=file], .ln select, .ln textarea {
        width: 100%; background: var(--td-surface); color: var(--td-ink); border: 1px solid var(--td-edge); border-radius: 8px; padding: .55rem .7rem; font: inherit; font-size: .9rem; }
    .ln textarea { min-height: 7rem; }
    .ln input:focus-visible, .ln select:focus-visible, .ln textarea:focus-visible, .ln a:focus-visible, .ln button:focus-visible, .ln summary:focus-visible { outline: 2px solid var(--td-focus); outline-offset: 2px; }
    .ln label.choice { font-size: .95rem; font-weight: 400; color: var(--td-ink); display: flex; gap: .5rem; align-items: center; padding: .25rem 0; }
    .ln .hint { font-size: .78rem; color: var(--td-ink-2); margin-top: .25rem; }
    .ln .btn { display: inline-flex; align-items: center; gap: .4rem; min-height: 44px; padding: .55rem 1rem; border-radius: 8px; border: 1px solid var(--td-primary); background: var(--td-primary); color: var(--td-primary-ink); font-weight: 700; font-size: .85rem; cursor: pointer; text-decoration: none; }
    .ln .btn.ghost { background: transparent; color: var(--td-primary); border-color: var(--td-edge); }
    .ln .btn.danger { background: transparent; color: var(--td-danger-ink); border-color: var(--td-danger); }
    .ln .btn:hover { filter: brightness(1.08); }
    .ln a { color: var(--td-primary); text-decoration: underline; text-underline-offset: 3px; }
    .ln .tag { display: inline-block; font-size: .72rem; font-weight: 600; padding: .1rem .5rem; border-radius: 999px; border: 1px solid var(--td-edge); color: var(--td-ink); }
    .ln .tag.ok { border-color: var(--td-success); color: var(--td-success-ink); }
    .ln .tag.warn { border-color: var(--td-warning); color: var(--td-warning-ink); }
    .ln .notice { padding: .75rem 1rem; border-radius: 8px; border: 1px solid var(--td-edge); background: var(--td-sunken); margin: .75rem 0; }
    .ln .notice.error { border-color: var(--td-danger); background: var(--td-danger-soft); color: var(--td-danger-ink); }
    .ln .notice.success { border-color: var(--td-success); background: var(--td-success-soft); color: var(--td-success-ink); }
    .ln ul.plain { list-style: none; padding: 0; margin: 0; }
    .ln ul.plain > li { padding: .65rem 0; border-top: 1px solid var(--td-line); max-width: none; }
    .ln .prose { line-height: 1.65; font-size: 1rem; }
    .ln .prose code { background: var(--td-sunken); padding: .05rem .3rem; border-radius: 4px; }
    .ln table { width: 100%; border-collapse: collapse; font-size: .85rem; font-variant-numeric: tabular-nums; }
    .ln th, .ln td { text-align: left; padding: .5rem .4rem; border-top: 1px solid var(--td-line); vertical-align: top; }
    .ln fieldset { border: 1px solid var(--td-line); border-radius: 8px; padding: .75rem 1rem 1rem; margin: 0 0 1rem; }
    .ln legend { padding: 0 .4rem; font-weight: 600; color: var(--td-ink); }
    .ln .sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
    .ln ::selection { background: var(--td-primary-soft); }
</style>
