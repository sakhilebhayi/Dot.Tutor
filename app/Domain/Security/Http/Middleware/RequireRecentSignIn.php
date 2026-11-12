<?php

namespace App\Domain\Security\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sensitive changes (a payout bank account) need proof that the person at the keyboard is the account holder: a
 * sign-in or a password confirmation within `security.reauth_minutes`. Accounts signed in through InfoDot prove it
 * by signing in again. Alias `tutor.recent-auth` (TutorSecurityServiceProvider). R-47 security S-13.
 */
class RequireRecentSignIn
{
    public const SIGNED_IN_AT = 'tutor.signed_in_at';

    public function handle(Request $request, Closure $next): Response
    {
        $window = max(1, (int) config('security.reauth_minutes', 15)) * 60;
        $session = $request->hasSession() ? $request->session() : null;
        $latest = max((int) $session?->get('auth.password_confirmed_at', 0), (int) $session?->get(self::SIGNED_IN_AT, 0));

        if ($latest > 0 && time() - $latest <= $window) {
            return $next($request);
        }

        $message = 'For your security, sign in again (or confirm your password) before changing your bank details.';

        if ($request->expectsJson()) {
            return response()->json(['error' => 'reauthentication_required', 'message' => $message], 423);
        }

        return back()->with('error', $message);
    }
}
