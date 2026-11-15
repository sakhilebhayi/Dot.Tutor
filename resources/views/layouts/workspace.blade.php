{{-- Tutor workspace shell: <x-layouts::workspace title="..."> page </x-layouts::workspace>. Props: title (the page name, also the
     <title>), step (young | standard | dense; default standard), livewire (true when the page uses Livewire components).
     Navigation comes from config/tutor_nav.php ('workspace'). --}}
@props(['title' => null, 'step' => 'standard', 'livewire' => false])
@include('layouts.partials.frame', [
    'shell' => 'workspace',
    'scope' => 'Tutor workspace',
    'nav' => app(\App\View\ShellNavigation::class)->for('workspace', $step),
    'bottom' => [],
    'step' => $step,
    'title' => $title,
    'content' => $slot,
    'livewire' => $livewire,
])
