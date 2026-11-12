{{-- The signed-in frame shared by the learner, tutor workspace, operations and legacy app shells.
     Expects: $shell (learner|workspace|operations|app), $scope (rail subtitle), $nav (ShellNavigation sections),
     $bottom (bottom-bar items, learner only), $step (young|standard|dense), $title, $topTitle (HTML or null),
     $content (the page), $livewire (bool). --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @if ($step !== 'standard') data-td-step="{{ $step }}" @endif>
<head>
    @include('layouts.partials.head', ['title' => $title, 'livewire' => $livewire])
</head>
<body class="td-app td-app--{{ $shell }}">
    <a class="td-skip" href="#main-content">Skip to content</a>

    <aside class="td-rail sidebar" id="app-sidebar" aria-label="Main navigation" tabindex="-1">
        <div class="td-rail__brand">
            <a class="td-wordmark" href="{{ url('/') }}">
                <img src="{{ asset('images/mark-64.png') }}" alt="" width="32" height="32">
                <span>Dot.Tutor<span class="td-wordmark__scope">{{ $scope }}</span></span>
            </a>
            <x-td.button variant="quiet" icon="x" icon-only class="td-rail__close" data-shell-close aria-label="Close menu">Close menu</x-td.button>
        </div>

        <nav class="td-rail__nav" aria-label="{{ $scope }}">
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

        @auth
            @php
                // Jetstream teams mean nothing to a learner, a child or a guardian ("Sipho's Team"). The switcher is shown
                // only to a tutor or operator who really uses teams (more than one); R-47 polish D-2023, critic A 13.
                $frameUser = Auth::user();
                $showTeams = Laravel\Jetstream\Jetstream::hasTeamFeatures() && $frameUser->currentTeam
                    && ($frameUser->is_platform_operator || $frameUser->tutorProfile()->exists())
                    && $frameUser->allTeams()->count() > 1;
            @endphp
            <div class="td-rail__foot sidebar-footer">
                <div class="td-rail__user">
                    <x-td.avatar :name="Auth::user()->name" :src="Auth::user()->profile_photo_path ? Auth::user()->profile_photo_url : null" size="sm" decorative />
                    <div style="min-width:0;flex:1">
                        <div class="td-rail__user-name">{{ Auth::user()->name }}</div>
                        @if ($showTeams)<div class="td-rail__user-meta">{{ $frameUser->currentTeam->name }}</div>@endif
                    </div>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <x-td.button type="submit" variant="quiet" icon="log-out" icon-only aria-label="Sign out" title="Sign out">Sign out</x-td.button>
                    </form>
                </div>
                @if ($showTeams)
                    <details class="td-rail__teams team-switch">
                        <summary class="td-nav-link"><x-td.icon name="users" /><span style="flex:1">Teams</span><x-td.icon name="chevron-down" /></summary>
                        <div class="td-rail__teams-list">
                            <a href="{{ route('teams.show', Auth::user()->currentTeam->id) }}" class="td-nav-link" @if (request()->routeIs('teams.show')) aria-current="page" @endif>Team settings</a>
                            @can('create', Laravel\Jetstream\Jetstream::newTeamModel())
                                <a href="{{ route('teams.create') }}" class="td-nav-link" @if (request()->routeIs('teams.create')) aria-current="page" @endif>Create new team</a>
                            @endcan
                            @if (Auth::user()->allTeams()->count() > 1)
                                <p class="td-rail__section">Switch teams</p>
                                @foreach (Auth::user()->allTeams() as $switchTeam)
                                    <form method="POST" action="{{ route('current-team.update') }}">
                                        @method('PUT')
                                        @csrf
                                        <input type="hidden" name="team_id" value="{{ $switchTeam->id }}">
                                        <button type="submit" class="td-nav-link" @if (Auth::user()->isCurrentTeam($switchTeam)) aria-current="true" @endif>{{ $switchTeam->name }}</button>
                                    </form>
                                @endforeach
                            @endif
                        </div>
                    </details>
                @endif
            </div>
        @endauth
    </aside>

    <div class="shell-backdrop" data-shell-close aria-hidden="true"></div>

    <header class="td-top topbar" data-shell-behind>
        <x-td.button variant="quiet" icon="menu" class="td-top__menu shell-menu-btn" data-shell-open aria-controls="app-sidebar" aria-expanded="false">Menu</x-td.button>
        <div class="td-top__title">{{ $topTitle ?? $title ?? 'Dot.Tutor' }}</div>
        <div class="td-top__actions">
            @if (Route::has('comms.inbox'))<x-comms::bell />@endif
            <x-td.theme-switch />
            @auth
                <x-td.button variant="quiet" icon="user" icon-only :href="route('profile.show')" aria-label="Your account">Your account</x-td.button>
            @endauth
        </div>
    </header>

    {{ $legacyNav ?? '' }}

    <div class="td-main content-wrap" data-shell-behind>
        <main id="main-content" class="td-page" tabindex="-1">
            {{ $content }}
        </main>
    </div>

    @if (! empty($bottom))
        <nav class="td-bottom" aria-label="Quick links" data-shell-behind>
            @foreach ($bottom as $item)
                <a href="{{ $item['href'] }}" @if ($item['current']) aria-current="page" @endif><x-td.icon :name="$item['icon']" />{{ $item['label'] }}</a>
            @endforeach
        </nav>
    @endif

    <x-td.toasts />
    @stack('modals')
    @include('layouts.partials.drawer-script')
    @if ($livewire)
        @livewireScripts
    @endif
</body>
</html>
