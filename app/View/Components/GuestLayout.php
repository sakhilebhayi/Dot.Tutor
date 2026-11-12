<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

class GuestLayout extends Component
{
    /**
     * Livewire's script (which carries Alpine) is 164 KB uncompressed; only the two-factor challenge uses Alpine on
     * these pages, so every other guest page leaves it out (R-47 D-1611, docs/R47_PERF.md section 4).
     */
    public function __construct(public bool $livewire = false, public ?string $title = null, public ?string $description = null) {}

    /**
     * Get the view / contents that represents the component.
     */
    public function render(): View
    {
        return view('layouts.guest', ['livewire' => $this->livewire, 'pageTitle' => $this->title, 'pageDescription' => $this->description]);
    }
}
