<?php

namespace App\Domain\Ops\Http\Controllers;

use App\Domain\Ops\Access\OpsPermission as P;
use App\Domain\Ops\Insights\Overview;
use App\Domain\Ops\Insights\Queues;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/** The command centre's first screen: what needs a person now (the queues this operator can work), then the platform's real figures. */
class OverviewController extends OpsController
{
    public function __invoke(Request $request, Overview $overview, Queues $queues): View
    {
        return view('ops.overview', [
            'queues' => $queues->for($request->user()),
            'data' => $overview->compute($this->can(P::INSIGHTS_MONEY)),
        ]);
    }
}
