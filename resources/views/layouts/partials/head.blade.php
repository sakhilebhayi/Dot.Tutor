{{-- Shared <head> for every shell: no third-party hosts. Fonts, icons and scripts come from this app.
     Expects $title (string|null) and optionally $description, $livewire (bool) and $dotUi (bool: the signed-in frame loads the shared Dot UI). --}}
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
@php
    // Search and sharing (R-47 polish D-2014): one title per page, a description, the canonical address (no query
    // string) and Open Graph / Twitter cards with the self-hosted card image. Pages pass title and description.
    $metaTitle = filled($title ?? null) ? $title.' | Dot.Tutor' : 'Dot.Tutor';
    $metaDescription = filled($description ?? null) ? $description : 'Dot.Tutor matches learners from Grade 1 to doctoral study with tutors by subject, level, curriculum and the language they really teach in, and says why each one fits.';
    $metaCanonical = $canonical ?? url()->current();
@endphp
<title>{{ $metaTitle }}</title>
<meta name="description" content="{{ $metaDescription }}">
<link rel="canonical" href="{{ $metaCanonical }}">
<meta property="og:type" content="website">
<meta property="og:site_name" content="Dot.Tutor">
<meta property="og:title" content="{{ $metaTitle }}">
<meta property="og:description" content="{{ $metaDescription }}">
<meta property="og:url" content="{{ $metaCanonical }}">
<meta property="og:image" content="{{ asset('images/og-card.png') }}">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="Dot.Tutor: tutors by subject, level and language, beside eight coloured book spines, one per stage of study.">
<meta property="og:locale" content="en_ZA">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $metaTitle }}">
<meta name="twitter:description" content="{{ $metaDescription }}">
<meta name="twitter:image" content="{{ asset('images/og-card.png') }}">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#f4f7f5" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#121a17" media="(prefers-color-scheme: dark)">
<link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
<link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
{{-- No font preloads (R-47 D-1613): on 3G the two preloaded fonts (87 KB) competed with the render-blocking stylesheet
     and held first paint back by 0.3 s (Fast 3G) to 1.1 s (Slow 3G). The faces load after the stylesheet with
     font-display: swap, so text shows at once in the fallback face. --}}
{{-- Applies a theme the person chose on this device before first paint (the design-system theme script keeps it).
     The page cross-fade (shells.css, D-2311) is skipped when a navigation interrupts it or the tab is hidden; its
     promises are caught here so a skipped fade is not reported as an error. --}}
<script @if (\Illuminate\Support\Facades\Vite::cspNonce()) nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}" @endif>document.documentElement.classList.add('js');try{var t=localStorage.getItem('td-theme');if(t==='light'||t==='dark'){document.documentElement.setAttribute('data-theme',t)}}catch(e){}function q(e){var v=e.viewTransition;if(v){[v.ready,v.finished,v.updateCallbackDone].forEach(function(p){p&&p.catch(function(){})})}}addEventListener('pageswap',q);addEventListener('pagereveal',q);addEventListener('unhandledrejection',function(e){var r=e.reason;if(r&&/^(AbortError|InvalidStateError)$/.test(r.name)&&/[Tt]ransition/.test(r.message||'')){e.preventDefault()}});</script>
@if ($dotUi ?? false)
    {{-- Shared Dot UI (platform switcher, compact filters), served from this site's own public/dot-ui ('self' only, no inline style or script). Signed-in shells only. Loaded before the app CSS; the host mapping block in resources/css/ds/dot-ui.css has the last word. --}}
    <link rel="stylesheet" href="{{ asset('dot-ui/css/dot-fonts.css') }}">
    <link rel="stylesheet" href="{{ asset('dot-ui/css/dot-tokens.css') }}">
    <link rel="stylesheet" href="{{ asset('dot-ui/css/dot-search.css') }}">
    <link rel="stylesheet" href="{{ asset('dot-ui/css/dot-switcher-fonts.css') }}">
    <link rel="stylesheet" href="{{ asset('dot-ui/css/dot-switcher.css') }}">
@endif
@vite(['resources/css/app.css', 'resources/js/app.js'])
@if ($livewire ?? false)
    @livewireStyles
@endif
