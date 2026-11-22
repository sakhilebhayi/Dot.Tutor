<?php

namespace App\Support;

/**
 * Where the "Sign in" entry points of the signed-out pages go. With DOTSSO_ENABLED they go straight to the central
 * sign-in (no hop through /login); otherwise they behave exactly as before. The shared package's interception would
 * catch /login anyway, so this only saves the extra redirect. There is no "Create account" here: Dot.Tutor has no
 * sign-up of its own, and an account is only ever opened through the rules in NewAccountAdmission.
 */
class CentralSignIn
{
    public static function active(): bool
    {
        return (bool) config('dotsso.enabled') && ! (bool) config('dotsso.allow_local_login');
    }

    public static function login(): string
    {
        return self::active() ? route('dotsso.login') : route('login');
    }
}
