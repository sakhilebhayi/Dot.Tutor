<?php

namespace App\Domain\Classroom;

use App\Domain\Classroom\Console\SweepClassrooms;
use App\Domain\Classroom\Contracts\FollowUpAssigner;
use App\Domain\Classroom\Contracts\SummaryDrafter;
use App\Domain\Classroom\Seams\LearningFollowUpAssigner;
use App\Domain\Classroom\Seams\NullSummaryDrafter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * R-47 live classroom (D-800 onward): one classroom per booked lesson, WebRTC between browsers with signalling over
 * short polls, chat, whiteboard, shared notes, files, attendance and the after-lesson record. Everything is
 * registered here, never in route files (route:cache).
 */
class TutorClassroomServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Seams other domains may replace (the integration provider registers after this one).
        $this->app->bindIf(SummaryDrafter::class, NullSummaryDrafter::class, true);
        $this->app->bindIf(FollowUpAssigner::class, LearningFollowUpAssigner::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/resources/views', 'classroom');
        Blade::anonymousComponentPath(__DIR__.'/resources/views/components', 'classroom');

        // Per person, never per address: a family on one connection must not share a budget.
        $by = fn (Request $request) => $request->user()?->id ?: $request->ip();
        RateLimiter::for('classroom-poll', fn (Request $request) => Limit::perMinute(150)->by('poll:'.$by($request)));
        RateLimiter::for('classroom-signal', fn (Request $request) => Limit::perMinute(900)->by('signal:'.$by($request)));
        RateLimiter::for('classroom-board', fn (Request $request) => Limit::perMinute(600)->by('board:'.$by($request)));
        RateLimiter::for('classroom-actions', fn (Request $request) => Limit::perMinute(60)->by('actions:'.$by($request)));

        if ($this->app->runningInConsole()) {
            $this->commands([SweepClassrooms::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('classroom:sweep')->everyMinute()->withoutOverlapping()->onOneServer();
        });
    }
}
