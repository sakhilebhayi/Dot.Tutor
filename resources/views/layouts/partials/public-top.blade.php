{{-- Public header: wordmark, the landing's sections, theme, and the one action (sign in, or back to your desk). --}}
<header class="td-public-top">
    <div class="td-wrap td-public-top__bar">
        <a class="td-wordmark" href="{{ url('/') }}">
            <img src="{{ asset('images/mark-64.png') }}" alt="" width="32" height="32">
            <span>Dot.Tutor</span>
        </a>
        @include('layouts.partials.public-nav')
        <div class="td-public-top__actions">
            <x-td.theme-switch />
            @auth
                <x-td.button variant="primary" :href="route('dashboard')">Your desk</x-td.button>
            @else
                @if (Route::has('login') && ! request()->routeIs('login'))
                    <x-td.button variant="primary" :href="route('login')">Sign in</x-td.button>
                @endif
            @endauth
            @include('layouts.partials.public-menu-button')
        </div>
    </div>
</header>
