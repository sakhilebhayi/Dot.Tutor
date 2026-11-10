<style>
    .m-sum { display:grid; grid-template-columns:1fr auto; gap:.4rem 1.5rem; margin:1rem 0 0; font-size:.85rem; max-width:28rem; }
    .m-sum dt { color:#a1a1aa; margin:0; }
    .m-sum dd { margin:0; text-align:right; font-family:'JetBrains Mono',monospace; font-variant-numeric:tabular-nums; color:#e4e4e7; }
    .m-sum .m-total { padding-top:.6rem; border-top:1px solid rgba(255,255,255,0.12); font-weight:700; color:#f4f4f5; font-size:.95rem; }
    .m-num { text-align:right; font-family:'JetBrains Mono',monospace; font-variant-numeric:tabular-nums; white-space:nowrap; }
    .m-note { margin:1rem 0 0; padding:.7rem 1rem; border-radius:8px; background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.1); color:#d4d4d8; font-size:.82rem; max-width:62ch; }
    .m-note-warn { background:rgba(251,191,36,0.08); border-color:rgba(251,191,36,0.3); color:#fcd34d; }
    .m-actions { display:flex; flex-wrap:wrap; gap:.75rem; align-items:center; margin-top:1.25rem; }
    .m-balances { display:grid; grid-template-columns:repeat(auto-fit, minmax(11rem, 1fr)); gap:1rem; margin:0; }
    .m-balances div { margin:0; }
    .m-balances dt { font-size:.75rem; color:#a1a1aa; margin:0 0 .2rem; }
    .m-balances dd { margin:0; font-family:'JetBrains Mono',monospace; font-variant-numeric:tabular-nums; font-size:1.05rem; color:#f4f4f5; }
    .t-table th, .t-table td { padding:.6rem .75rem; text-align:left; border-bottom:1px solid rgba(255,255,255,0.06); vertical-align:top; }
    .t-table th { font-size:.68rem; text-transform:uppercase; letter-spacing:.08em; color:#a1a1aa; font-weight:700; }
    .t-btn:focus-visible, .t-field:focus-visible, a:focus-visible { outline:2px solid #fbbf24; outline-offset:2px; }
    .t-btn[disabled] { opacity:.5; cursor:not-allowed; }
    .t-table.m-lines { min-width:0; table-layout:auto; }
    .m-lines td:first-child { min-width:9rem; overflow-wrap:anywhere; }
    @media (max-width: 640px) { .m-lines th, .m-lines td { padding:.55rem .4rem; } .m-hide-sm { display:none; } }
    @media print {
        .sidebar, .topbar, .m-noprint { display:none !important; }
        .m-hide-sm { display:table-cell !important; }
        .content-wrap { margin:0 !important; padding:0 !important; }
        body { background:#fff !important; color:#000 !important; }
        .t-panel { background:#fff !important; border-color:#ccc !important; }
        .t-h1, .t-h2, .m-sum dd, .m-sum dt, .t-table td, .t-table th, .m-receipt * { color:#000 !important; }
    }
</style>
