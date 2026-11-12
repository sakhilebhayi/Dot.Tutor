<?php

namespace App\Domain\Ops\Http\Controllers;

use App\Domain\Academic\Http\Controllers\Operator\AcademicResourceController;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Academic structure inside the command centre. The academic agent owns the CRUD (AcademicResourceController,
 * OperatorResources, its audit seam); this only renders it at the operations shell's own addresses. Its views
 * are restyled on the design system by view overrides in resources/views/ops/overrides/academic.
 */
class AcademicController extends OpsController
{
    public function home(AcademicResourceController $academic): View
    {
        return $academic->home();
    }

    public function subjects(Request $request, AcademicResourceController $academic): View
    {
        return $academic->index($request, 'subjects');
    }

    public function languages(Request $request, AcademicResourceController $academic): View
    {
        return $academic->index($request, 'languages');
    }
}
