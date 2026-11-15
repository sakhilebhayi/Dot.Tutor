{{-- The signed-in frame shared by the learner, tutor workspace, operations and legacy app shells.
     Expects: $shell (learner|workspace|operations|app), $scope (rail subtitle), $nav (ShellNavigation sections),
     $bottom (bottom-bar items, learner only), $step (young|standard|dense), $title, $content (the page), $livewire (bool).
     R-52: the top bar is the shared dot-ui header (one row: brand, centred search, switcher, bell, account menu). The rail below it is the
     platform's own navigation; on a phone the header's menu button opens it (dot-ui adopt mode, drawer-target). --}}
<!DOCTYPE html>
@php
    // The tutor workspace has one shell size: a dense page tightens its own content, never the rail and top bar
    // (R-47 fix D-2403; critic B saw smaller nav and icons on the dense /teach pages). Young and operations keep the
    // step on the whole document.
    $contentStep = $shell === 'workspace' && $step === 'dense' ? 'dense' : null;
    $rootStep = $step !== 'standard' && $contentStep === null ? $step : null;
@endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dot-roboto" data-dh-theme-key="td-theme" @if ($rootStep) data-td-step="{{ $rootStep }}" @endif>
<head>
    @include('layouts.partials.head', ['title' => $title, 'livewire' => $livewire, 'dotUi' => true])
</head>
<body class="td-app td-app--{{ $shell }}">
    <a class="td-skip" href="#main-content">Skip to content</a>

    @auth
        @php
            $shellHeader = app(\App\Support\ShellHeader::class);
            $headerAccount = $shellHeader->account(Auth::user(), $shell);
            $headerUnread = $shellHeader->unread(Auth::user());
            $headerSearchScope = $shellHeader->searchScope($shell);
            $headerSwitcher = $shellHeader->switcher(Auth::user());
            $headerSearch = app(\App\Support\Search\TutorSearch::class);
            $headerPages = $headerSearch->pages(Auth::user(), $headerSearchScope, null, 6);
        @endphp
    @endauth

    <x-dot.header id="dh" brand-href="{{ url('/') }}" brand-label="Dot.Tutor, {{ $scope }}" :account="$headerAccount ?? null"
        drawer-target="app-sidebar" menu-label="Open menu" search-label="Search Dot.Tutor" context="{{ $scope }}">
        <x-slot:brand>
            <img class="td-brand-mark" src="{{ asset('images/mark-64.png') }}" alt="" width="32" height="32">
            <span class="dh-brand__name">Dot.Tutor</span>
        </x-slot:brand>
        @auth
            <x-slot:search>
                {{-- Real search (R-52), scoped to this shell: what a learner, guardian, tutor or operator may already open (App\Support\Search\TutorSearch).
                     A plain GET form to /search works with no script; js/ds/header-search.js fills the dropdown from /search/suggest. --}}
                <x-dot.search name="q" id="dh-search-q" :action="route('search')" :value="request()->routeIs('search') ? request('q') : ''" label="Search Dot.Tutor"
                    placeholder="Search Dot.Tutor" hint="Ctrl K" results-nav loading-text="Searching…"
                    :hidden-fields="['scope' => $headerSearchScope]" :root-attributes="['data-tutor-search' => '', 'data-suggest-url' => route('search.suggest')]">
                    <x-slot:results>
                        <div data-search-pages>
                            <div class="dsr-head">Go to</div>
                            <div class="dsr-chips">
                                @foreach ($headerPages as $page)
                                    <a class="dsr-chip" role="option" href="{{ $page->url }}">{{ $page->title }}</a>
                                @endforeach
                            </div>
                            <p class="dsr-foot">Search your {{ $headerSearchScope === 'teach' ? 'students, lessons and courses' : ($headerSearchScope === 'ops' ? 'people, tutors, orders and incidents' : ($headerSearchScope === 'guardian' ? 'children and approvals' : 'tutors, lessons, courses and library')) }}. Type at least {{ \App\Support\Search\TutorSearch::MIN_LENGTH }} characters.</p>
                        </div>
                    </x-slot:results>
                </x-dot.search>
            </x-slot:search>
            <x-slot:tools>
                <x-dot.switcher platform="tutor" :model="$headerSwitcher['model']" :company="$headerSwitcher['company']" :company-name="$headerSwitcher['name']" logos="/dot-ui/img/platforms" />
                @if (Route::has('comms.inbox'))
                    <x-dot.bell :href="route('comms.inbox')" :count="$headerUnread" always />
                @endif
            </x-slot:tools>
        @endauth
    </x-dot.header>

    <aside class="td-rail sidebar" id="app-sidebar" aria-label="Main navigation" tabindex="-1">
        <div class="td-rail__brand">
            <a class="td-wordmark" href="{{ url('/') }}">
                <img src="{{ asset('images/mark-64.png') }}" alt="" width="32" height="32">
                <span>Dot.Tutor<span class="td-wordmark__scope">{{ $scope }}</span></span>
            </a>
            <x-td.button variant="quiet" icon="x" icon-only class="td-rail__close" data-dh-close aria-label="Close menu">Close menu</x-td.button>
        </div>

        <nav class="td-rail__nav" aria-label="{{ $scope }}">
            <p class="td-rail__scope">{{ $scope }}</p>
            @foreach ($nav as $section)
                @if ($section['label'])
                    <p class="td-rail__section">{{ $section['label'] }}</p>
                @endif
                @foreach ($section['items'] as $item)
                    <a class="td-nav-link" href="{{ $item['href'] }}" @if ($item['current']) aria-current="page" @endif>
                        <x-td.icon :name="$item['icon']" />{{ $item['label'] }}
                    </a>
                @endforeach
            @endforeach
        </nav>
    </aside>

    <div class="td-main content-wrap" @if ($contentStep) data-td-step="{{ $contentStep }}" @endif>
        <main id="main-content" class="td-page" tabindex="-1">
            {{ $content }}
        </main>
    </div>

    @if (! empty($bottom))
        <nav class="td-bottom" aria-label="Quick links">
            @foreach ($bottom as $item)
                <a href="{{ $item['href'] }}" @if ($item['current']) aria-current="page" @endif><x-td.icon :name="$item['icon']" />{{ $item['label'] }}</a>
            @endforeach
        </nav>
    @endif

    <x-td.toasts />
    @stack('modals')
    <script src="{{ asset('dot-ui/js/dot-search.js') }}" defer></script>
    <script src="{{ asset('dot-ui/js/dot-search-results.js') }}" defer></script>
    <script src="{{ asset('dot-ui/js/dot-switcher.js') }}" defer></script>
    <script src="{{ asset('dot-ui/js/dot-nav.js') }}" defer></script>
    @if ($livewire)
        @livewireScripts
    @endif
</body>
</html>
