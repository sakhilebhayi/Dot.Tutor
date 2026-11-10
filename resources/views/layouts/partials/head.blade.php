{{-- Shared <head> for every shell: no third-party hosts. Fonts, icons and scripts come from this app.
     Expects $title (string|null) and optionally $description and $livewire (bool). --}}
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>{{ filled($title ?? null) ? $title.' | Dot.Tutor' : 'Dot.Tutor' }}</title>
@if (filled($description ?? null))
    <meta name="description" content="{{ $description }}">
@endif
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#f4f7f5" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#121a17" media="(prefers-color-scheme: dark)">
<link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
<link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
<link rel="preload" href="{{ asset('fonts/atkinson-hyperlegible-next-latin-wght-normal.woff2') }}" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="{{ asset('fonts/archivo-display-latin.woff2') }}" as="font" type="font/woff2" crossorigin>
{{-- Applies a theme the person chose on this device before first paint (resources/js/ds/theme.js keeps it). --}}
<script @if (\Illuminate\Support\Facades\Vite::cspNonce()) nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}" @endif>try{var t=localStorage.getItem('td-theme');if(t==='light'||t==='dark'){document.documentElement.setAttribute('data-theme',t)}}catch(e){}</script>
@vite(['resources/css/app.css', 'resources/js/app.js'])
@if ($livewire ?? false)
    @livewireStyles
@endif
