<?php

namespace App\Domain\Teach\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Workspace pages need a tutor profile (any state, a draft included). Without one, the person is sent to the
 * guided onboarding, which is where a profile starts. Authorisation of each object stays in the controllers.
 */
class RequireTutorProfile
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->tutorProfile()->doesntExist()) {
            return redirect()->route('teach.profile');
        }

        return $next($request);
    }
}
