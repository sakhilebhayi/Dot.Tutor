{{-- Learning shell: <x-layouts::learner title="..."> page </x-layouts::learner>. Props: title (the page name, also the
     <title>), step (young | standard | dense; default standard), livewire (true when the page uses Livewire components).
     Slot "top" replaces the top-bar title. Navigation comes from config/tutor_nav.php ('learner'). --}}
@props(['title' => null, 'step' => 'standard', 'livewire' => false])
{{-- A guardian gets the guardian navigation in this frame (App\Domain\Ops\Guardian\GuardianShell, D-2100). --}}
@php($frameNav = app(\App\Domain\Ops\Guardian\GuardianShell::class)->frame($step))
@include('layouts.partials.frame', [
    'shell' => 'learner',
    'scope' => $frameNav['scope'],
    'nav' => $frameNav['nav'],
    'bottom' => $frameNav['bottom'],
    'step' => $step,
    'title' => $title,
    'topTitle' => $top ?? null,
    'content' => $slot,
    'livewire' => $livewire,
])
