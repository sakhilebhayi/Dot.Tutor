<?php

namespace App\Providers;

use App\View\ShellNavigation;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

/**
 * The R-47 design system (ds agent, docs/R47_DS.md): makes the shells in resources/views/layouts usable as
 * components (<x-layouts::learner>, <x-layouts::workspace>, <x-layouts::operations>, <x-layouts::public>)
 * and resolves shell navigation from config/tutor_nav.php. The kit itself is anonymous components under
 * resources/views/components/td (<x-td.button>, <x-td.field> ...), which Blade finds without registration.
 */
class TutorDesignServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ShellNavigation::class);
    }

    public function boot(): void
    {
        Blade::anonymousComponentPath(resource_path('views/layouts'), 'layouts');
    }
}
