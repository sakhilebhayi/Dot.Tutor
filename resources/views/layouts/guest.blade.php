{{-- Guest layout (<x-guest-layout>) for sign-in, password, two-factor and the policy pages. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('layouts.partials.head', ['title' => null, 'livewire' => true])
</head>
<body class="td-guest-body">
    <a class="td-skip" href="#main-content">Skip to content</a>
    <div class="td-guest">
        <div>
            <div class="td-guest-band" aria-hidden="true"><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span></div>
            @include('layouts.partials.public-top')
        </div>
        <main id="main-content" tabindex="-1">
            {{ $slot }}
        </main>
        @include('layouts.partials.public-foot', ['credits' => []])
    </div>
    <x-td.toasts />
    @livewireScripts
</body>
</html>
