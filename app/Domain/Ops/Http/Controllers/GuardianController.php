<?php

namespace App\Domain\Ops\Http\Controllers;

use App\Domain\Ops\Guardian\GuardianView;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The guardian experience (brief section 15), phone-first in the learner shell. All data comes from safety's
 * GuardianDashboard (the guardian's own linked children only) plus the money orders those children placed; every
 * decision posts to the safety domain's own guardian endpoints, which check the link server-side.
 */
class GuardianController extends Controller
{
    public function home(Request $request, GuardianView $view): View
    {
        return view('guardian.home', $view->for($request->user()));
    }

    public function child(Request $request, int $learner, GuardianView $view): View
    {
        $data = $view->for($request->user());
        $child = collect($data['children'])->firstWhere('id', $learner);
        abort_if($child === null, 404);

        return view('guardian.child', $data + ['child' => $child]);
    }
}
