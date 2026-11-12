<?php

namespace App\Http\Responses;

use App\Support\RoleHome;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse;

/**
 * After a password, two-factor sign-in: the page the person asked for if there was one
 * (intended URL), otherwise their role's home (App\Support\RoleHome, D-1801) instead of the legacy /dashboard.
 */
class RoleHomeLoginResponse implements LoginResponse, TwoFactorLoginResponse
{
    public function __construct(private readonly RoleHome $home) {}

    public function toResponse($request)
    {
        if ($request->wantsJson()) {
            return response()->json(['two_factor' => false]);
        }

        return redirect()->intended($this->home->for($request->user()));
    }
}
