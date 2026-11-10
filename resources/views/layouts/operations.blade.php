{{-- Operations shell: <x-layouts::operations title="..."> page </x-layouts::operations>. Props: title (the page name, also the
     <title>), step (young | standard | dense; default dense), livewire (true when the page uses Livewire components).
     Slot "top" replaces the top-bar title. Navigation comes from config/tutor_nav.php ('operations'). --}}
@props(['title' => null, 'step' => 'dense', 'livewire' => false])
@include('layouts.partials.frame', [
    'shell' => 'operations',
    'scope' => 'Operations',
    'nav' => app(\App\View\ShellNavigation::class)->for('operations', $step),
    'bottom' => [],
    'step' => $step,
    'title' => $title,
    'topTitle' => $top ?? null,
    'content' => $slot,
    'livewire' => $livewire,
])
