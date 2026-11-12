{{-- One legal page: $title (the page h1) and $html (Markdown rendered by the route). --}}
<article class="td-wrap td-legal">
    <header class="td-legal__head">
        <h1 class="td-legal__title">{{ $title }}</h1>
        <nav class="td-legal__nav" aria-label="Policies">
            <a href="{{ route('terms.show') }}" @if (request()->routeIs('terms.show')) aria-current="page" @endif>Terms</a>
            <a href="{{ route('policy.show') }}" @if (request()->routeIs('policy.show')) aria-current="page" @endif>Privacy</a>
            <a href="{{ route('cookies') }}" @if (request()->routeIs('cookies')) aria-current="page" @endif>Cookies</a>
        </nav>
    </header>
    <div class="td-legal__body">
        {!! $html !!}
    </div>
</article>
