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
{{-- Roboto (R-52, dot-ui 0.6.1): the one text face, from the shared dot-ui files on this origin (no CDN, font-src 'self'), on every page type. Signed-in shells link ONE minified stylesheet
     (dot-ui.min.css: Roboto, type scale, tokens, search, switcher, header; first in the head, before the app CSS); public pages link only the two small files (no header CSS). The latin file IS preloaded on every page: without it
     the lesson page's header row (CLS 0.42 at 768px) and the public top bar (CLS 0.105 at 320px) re-wrap when Roboto replaces the fallback (measured); it does not delay the largest paint here. --}}
<link rel="preload" href="{{ asset('dot-ui/fonts/roboto/roboto-latin-wght-normal.woff2') }}" as="font" type="font/woff2" crossorigin>
@if ($dotUi ?? false)
<link rel="stylesheet" href="{{ asset('dot-ui/css/dot-ui.min.css') }}">
@else
<link rel="stylesheet" href="{{ asset('dot-ui/css/dot-roboto.min.css') }}">
<link rel="stylesheet" href="{{ asset('dot-ui/css/dot-type.min.css') }}">
@endif
{{-- A theme the person chose on this device, applied before first paint by the shared script (an external file, so it needs no nonce). The
     page cross-fade (shells.css, D-2311) is skipped when a navigation interrupts it or the tab is hidden; its promises are caught here
     so a skipped fade is not reported as an error. --}}
<script src="{{ asset('dot-ui/js/dot-theme-boot.min.js') }}"></script>
<script @if (\Illuminate\Support\Facades\Vite::cspNonce()) nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}" @endif>document.documentElement.classList.add('js');function q(e){var v=e.viewTransition;if(v){[v.ready,v.finished,v.updateCallbackDone].forEach(function(p){p&&p.catch(function(){})})}}addEventListener('pageswap',q);addEventListener('pagereveal',q);addEventListener('unhandledrejection',function(e){var r=e.reason;if(r&&/^(AbortError|InvalidStateError)$/.test(r.name)&&/[Tt]ransition/.test(r.message||'')){e.preventDefault()}});</script>
@vite(['resources/css/app.css', 'resources/js/app.js'])
@if ($livewire ?? false)
    @livewireStyles
@endif
