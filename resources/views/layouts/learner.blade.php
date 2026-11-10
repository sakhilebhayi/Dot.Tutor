{{-- Learning shell: <x-layouts::learner title="..."> page </x-layouts::learner>. Props: title (the page name, also the
     <title>), step (young | standard | dense; default standard), livewire (true when the page uses Livewire components).
     Slot "top" replaces the top-bar title. Navigation comes from config/tutor_nav.php ('learner'). --}}
@props(['title' => null, 'step' => 'standard', 'livewire' => false])
@include('layouts.partials.frame', [
    'shell' => 'learner',
    'scope' => 'Learning',
    'nav' => app(\App\View\ShellNavigation::class)->for('learner', $step),
    'bottom' => app(\App\View\ShellNavigation::class)->bottomBar('learner', $step),
    'step' => $step,
    'title' => $title,
    'topTitle' => $top ?? null,
    'content' => $slot,
    'livewire' => $livewire,
])
