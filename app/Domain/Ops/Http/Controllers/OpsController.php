<?php

namespace App\Domain\Ops\Http\Controllers;

use App\Domain\Ops\Access\OpsAccess;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

/** Shared helpers for the command centre's screens. Routes are authorised by RoutePermissions before they get here. */
abstract class OpsController extends Controller
{
    protected function access(): OpsAccess
    {
        return app(OpsAccess::class);
    }

    protected function can(string $permission): bool
    {
        return $this->access()->can(request()->user(), $permission);
    }

    protected function done(string $message): RedirectResponse
    {
        return back(303)->with('success', $message);
    }

    protected function refused(string $message): RedirectResponse
    {
        return back(303)->with('error', $message);
    }

    /** First name and last initial, the way the rest of Dot.Tutor shows people to staff lists. */
    protected static function shortName(?User $user): string
    {
        if ($user === null) {
            return 'Deleted account';
        }

        $parts = preg_split('/\s+/', trim((string) $user->name)) ?: [];

        return count($parts) > 1 ? $parts[0].' '.mb_substr(end($parts), 0, 1).'.' : (string) $user->name;
    }
}
