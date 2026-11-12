{{-- Error pages in the Dot.Tutor system. Self-contained on purpose: it must render even when the database,
     the session or the Vite manifest is unavailable, so it reads the manifest itself and falls back to
     readable unstyled markup. Sections: title, message, actions, optional icon (a sprite name in $icon). --}}
@php
    $viteManifestPath = public_path('build/manifest.json');
    $hasVite = false;
    if (file_exists($viteManifestPath)) {
        $viteManifest = json_decode(file_get_contents($viteManifestPath), true) ?? [];
        $hasVite = isset($viteManifest['resources/css/app.css'], $viteManifest['resources/js/app.js']);
    }

    // Every Dot platform from the shared ecosystem registry (config/ecosystem.php), except this one and the
    // internal services.
    $discover = collect(config('ecosystem.platforms', []))
        ->reject(fn ($p) => ($p['name'] ?? null) === 'Dot.Tutor')
        ->reject(fn ($p) => ($p['active'] ?? true) === false)
        ->reject(fn ($p, $k) => ($p['internal'] ?? false) === true || in_array($k, ['memory', 'brain', 'dopemine'], true))
        ->values()
        ->all();

    $signedIn = false;
    try {
        $signedIn = auth()->check();
    } catch (\Throwable) {
        $signedIn = false;
    }
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $code }}: @yield('title') | Dot.Tutor</title>
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    @if ($hasVite)
        {{-- A skipped page cross-fade (D-2311) is not an error; same guard as the shared head. --}}
        <script @if (\Illuminate\Support\Facades\Vite::cspNonce()) nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}" @endif>(function(){function q(e){var v=e.viewTransition;if(v){[v.ready,v.finished,v.updateCallbackDone].forEach(function(p){p&&p.catch(function(){})})}}addEventListener('pageswap',q);addEventListener('pagereveal',q);addEventListener('unhandledrejection',function(e){var r=e.reason;if(r&&/^(AbortError|InvalidStateError)$/.test(r.name)&&/[Tt]ransition/.test(r.message||'')){e.preventDefault()}});})();</script>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
</head>
<body class="td-public">
    <a class="td-skip" href="#main-content">Skip to content</a>
    <div class="td-guest">
        <div>
            <div class="td-guest-band" aria-hidden="true"><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span></div>
            <header class="td-public-top">
                <div class="td-wrap td-public-top__bar">
                    <a class="td-wordmark" href="{{ url('/') }}">
                        <img src="{{ asset('images/mark-64.png') }}" alt="" width="32" height="32">
                        <span>Dot.Tutor</span>
                    </a>
                    @include('layouts.partials.public-nav')
                    <div class="td-public-top__actions">
                        @if ($signedIn && Route::has('dashboard'))
                            <a class="td-btn td-btn--primary" href="{{ route('dashboard') }}"><span>Your desk</span></a>
                        @elseif (Route::has('login'))
                            <a class="td-btn td-btn--primary" href="{{ route('login') }}"><span>Sign in</span></a>
                        @endif
                        @include('layouts.partials.public-menu-button')
                    </div>
                </div>
            </header>
        </div>

        <main id="main-content" class="td-wrap err">
            <div class="err__grid">
                <div>
                    <p class="err__code">Error {{ $code }}</p>
                    <h1 class="err__title">@yield('title')</h1>
                    <p class="err__message">@yield('message')</p>
                    <div class="err__actions">
                        @yield('actions')
                    </div>
                </div>
                <div class="err__shelf" aria-hidden="true">
                    <span></span><span></span><span class="is-gap"></span><span></span><span></span><span></span><span></span><span></span>
                </div>
            </div>

            {{-- A page that was not found offers this site's own routes, not the other Dot platforms (critic A 22). --}}
            @if (! empty($discover) && ! View::hasSection('local-routes'))
                <nav class="err__eco" aria-labelledby="eco-title">
                    <h2 id="eco-title" class="err__eco-title">While you're here, the rest of the Dot Ecosystem ({{ count($discover) }})</h2>
                    <ul>
                        @foreach ($discover as $platform)
                            <li><a class="td-chip" href="{{ $platform['url'] }}">{{ $platform['name'] }}</a></li>
                        @endforeach
                    </ul>
                </nav>
            @endif
        </main>

        @include('layouts.partials.public-foot', ['credits' => []])
    </div>
</body>
</html>
