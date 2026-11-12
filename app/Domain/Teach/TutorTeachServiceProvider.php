<?php

namespace App\Domain\Teach;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * The tutor workspace (tutor UI agent, R-47 wave 2, decisions D-1200 onward): the screens a tutor runs a business
 * from. It owns no domain rules: every action goes through the marketplace, money, learning, academic and safety
 * services, which enforce them server side. Routes in routes/tutor/teach.php; views in resources/views/teach.
 */
class TutorTeachServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Rate limiters live here, never in route files (route:cache).
        RateLimiter::for('teach-referrals', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));
        RateLimiter::for('teach-exports', fn (Request $request) => Limit::perMinute(10)->by((string) ($request->user()?->id ?? $request->ip())));
    }
}
