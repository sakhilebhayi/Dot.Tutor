<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Per signed-in user (IP as the fallback), per minute. Limits live in config/tutor.php.
        RateLimiter::for('bookings', fn (Request $request) => Limit::perMinute(config('tutor.throttle.bookings'))
            ->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(config('tutor.throttle.uploads'))
            ->by($request->user()?->id ?: $request->ip()));
    }
}
