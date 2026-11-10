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
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
</head>
<body class="td-public">
    <div class="td-guest">
        <div>
            <div class="td-guest-band" aria-hidden="true"><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span></div>
            <header class="td-public-top">
                <div class="td-wrap td-public-top__bar">
                    <a class="td-wordmark" href="{{ url('/') }}">
                        <img src="{{ asset('images/mark.png') }}" alt="" width="32" height="32">
                        <span>Dot.Tutor</span>
                    </a>
                    <div class="td-public-top__actions">
                        @if ($signedIn && Route::has('dashboard'))
                            <a class="td-btn td-btn--primary" href="{{ route('dashboard') }}">Your desk</a>
                        @elseif (Route::has('login'))
                            <a class="td-btn" href="{{ route('login') }}">Sign in</a>
                        @endif
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

            @if (! empty($discover))
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

        <footer class="td-public-foot">
            <div class="td-wrap td-public-foot__legal" style="border-top:0">
                <span>&copy; {{ date('Y') }} Dot.Tutor.</span>
                @if (Route::has('contact'))
                    <a href="{{ route('contact') }}">Contact support</a>
                @endif
            </div>
        </footer>
    </div>
</body>
</html>
