<?php

namespace App\Http\Controllers;

use App\Support\RoleHome;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The older /dashboard is retired (R-51): it was a page of its own with a different look and a list of every subject.
 * Anyone who still arrives here (an old link, a bookmark, "Your desk") goes to the home for their role. The route
 * name stays so every existing link keeps working.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, RoleHome $home): RedirectResponse
    {
        return redirect($home->for($request->user()));
    }

    public function markNoticesRead(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return back();
    }
}
