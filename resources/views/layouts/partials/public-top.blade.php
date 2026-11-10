{{-- Public header: wordmark, the landing's sections, theme, and the one action (sign in, or back to your desk). --}}
@php $onHome = request()->is('/'); @endphp
<header class="td-public-top">
    <div class="td-wrap td-public-top__bar">
        <a class="td-wordmark" href="{{ url('/') }}">
            <img src="{{ asset('images/mark.png') }}" alt="" width="32" height="32">
            <span>Dot.Tutor</span>
        </a>
        <nav class="td-public-nav" aria-label="Dot.Tutor">
            <a href="{{ $onHome ? '#how' : url('/#how') }}">How matching works</a>
            <a href="{{ $onHome ? '#rooms' : url('/#rooms') }}">Rooms by level</a>
            <a href="{{ $onHome ? '#languages' : url('/#languages') }}">Languages</a>
            <a href="{{ $onHome ? '#teach' : url('/#teach') }}">Teach</a>
        </nav>
        <div class="td-public-top__actions">
            <x-td.theme-switch />
            @auth
                <x-td.button variant="primary" :href="route('dashboard')">Your desk</x-td.button>
            @else
                @if (Route::has('login'))
                    <x-td.button variant="primary" :href="route('login')">Sign in</x-td.button>
                @endif
            @endauth
        </div>
    </div>
</header>
