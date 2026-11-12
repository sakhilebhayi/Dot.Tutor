<?php

namespace App\Domain\Security;

use App\Domain\Security\Http\Middleware\RequireRecentSignIn;
use Illuminate\Auth\Events\Login;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * R-47 security wiring (docs/R47_SECURITY.md): the recent-sign-in middleware alias and the sign-in timestamp it
 * reads. Aliases, limiters and listeners live here, never in route files (route:cache).
 */
class TutorSecurityServiceProvider extends ServiceProvider
{
    public function boot(Router $router): void
    {
        $router->aliasMiddleware('tutor.recent-auth', RequireRecentSignIn::class);

        Event::listen(Login::class, function (Login $event): void {
            $request = request();

            if ($request->hasSession()) {
                $request->session()->put(RequireRecentSignIn::SIGNED_IN_AT, time());
            }
        });
    }
}
