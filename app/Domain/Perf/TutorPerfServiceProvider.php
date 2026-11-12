<?php

namespace App\Domain\Perf;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\ServiceProvider;

/**
 * R-47 performance (docs/R47_PERF.md, D-1600 onward): the lazy-touch database session handler and lean answers on
 * the polling routes. Nothing here changes what a page shows; it removes writes and bytes nobody needs.
 */
class TutorPerfServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // The "database" session driver with the same table and lifetime, minus the rewrite of an unchanged row on
        // every request (D-1608). Touch window: at most once a minute.
        Session::extend('database', function ($app) {
            $config = $app['config'];

            return new LazyTouchDatabaseSessionHandler(
                $app['db']->connection($config->get('session.connection')),
                $config->get('session.table'),
                $config->get('session.lifetime'),
                $app,
                (int) $config->get('session.touch_seconds', 60),
            );
        });

        // First in the global stack, so it sees the response after every other middleware added its headers.
        $this->app->make(Kernel::class)->prependMiddleware(LeanPollResponses::class);
    }
}
