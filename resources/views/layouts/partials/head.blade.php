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
{{-- No font preloads (R-47 D-1613): on 3G the two preloaded fonts (87 KB) competed with the render-blocking stylesheet
     and held first paint back by 0.3 s (Fast 3G) to 1.1 s (Slow 3G). The faces load after the stylesheet with
     font-display: swap, so text shows at once in the fallback face. --}}
{{-- Applies a theme the person chose on this device before first paint (the design-system theme script keeps it). --}}
<script @if (\Illuminate\Support\Facades\Vite::cspNonce()) nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}" @endif>document.documentElement.classList.add('js');try{var t=localStorage.getItem('td-theme');if(t==='light'||t==='dark'){document.documentElement.setAttribute('data-theme',t)}}catch(e){}</script>
@vite(['resources/css/app.css', 'resources/js/app.js'])
@if ($livewire ?? false)
    @livewireStyles
@endif
