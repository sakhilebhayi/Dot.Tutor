<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Livewire 3 bundles and starts Alpine. A second copy loaded from a CDN replaces window.Alpine and
 * breaks every Livewire re-render, so no layout may load one. The app layout renders through the shared
 * signed-in frame (layouts/partials/frame), which is where @livewireScripts lives since R-47.
 */
class SingleAlpineTest extends TestCase
{
    public function test_the_app_layout_does_not_load_a_second_alpine(): void
    {
        $views = dirname(__DIR__, 2).'/resources/views/layouts';
        $layout = file_get_contents($views.'/app.blade.php');
        $frame = file_get_contents($views.'/partials/frame.blade.php');

        $this->assertIsString($layout);
        $this->assertIsString($frame);
        $this->assertStringContainsString("@include('layouts.partials.frame'", $layout);
        $this->assertStringContainsString("'livewire' => true", $layout);

        foreach ([$layout, $frame] as $source) {
            $this->assertDoesNotMatchRegularExpression('/<script[^>]+alpinejs/i', $source);
        }
        $this->assertStringContainsString('@livewireScripts', $frame);
    }
}
