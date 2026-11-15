{{-- The Jetstream app layout (<x-app-layout>), kept so every existing signed-in page renders in the new system
     until wave 2 moves it to the learner, workspace or operations shell. Same frame as those shells; the old page
     classes are bridged by resources/css/ds/legacy.css under .td-app--app. --}}
@php
    $user = Auth::user();
    $link = fn (string $label, string $route, string $icon, string $match) => [
        'label' => $label,
        'href' => route($route),
        'icon' => $icon,
        'current' => request()->routeIs($match),
        'bottom' => false,
    ];

    $nav = [['label' => null, 'items' => [
        $link('Dashboard', 'dashboard', 'house', 'dashboard'),
        $link('Find a tutor', 'tutors.browse', 'search', 'tutors.*'),
    ]]];

    if ($user) {
        $nav[] = ['label' => 'Teaching', 'items' => $user->tutorProfile
            ? [
                $link('Tutor workspace', 'teach.home', 'badge-check', 'teach.*'),
            ]
            : [$link('Become a tutor', 'teach.profile', 'graduation-cap', 'teach.profile')]];

        if ($user->is_platform_operator) {
            $nav[] = ['label' => 'Operator', 'items' => [
                $link('Tutor review', 'operator.tutor-profiles.index', 'clipboard-check', 'operator.tutor-profiles.*'),
                $link('Subjects', 'operator.subjects.index', 'book-marked', 'operator.subjects.*'),
            ]];
        }
    }

    $nav[] = ['label' => 'You', 'items' => [$link('Profile and settings', 'profile.show', 'settings', 'profile.show')]];
@endphp
@include('layouts.partials.frame', [
    'shell' => 'app',
    'scope' => 'Learning and teaching',
    'nav' => $nav,
    'bottom' => [],
    'step' => 'standard',
    'title' => null,
    // The old top bar printed the page's header slot as the title; the shared header has no room for a title, so it leads the page.
    'content' => new \Illuminate\Support\HtmlString((isset($header) && trim((string) $header) !== '' ? '<div class="td-legacy-head">'.$header.'</div>' : '').$slot),
    'livewire' => true,
])
