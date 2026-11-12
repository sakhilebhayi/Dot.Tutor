{{-- Safety's guardian dashboard page (GET /safety/guardian) is the guardian's Safety page (D-2101): reports, cases
     and notices about their own children. JSON callers of that route are unaffected. --}}
@include('guardian.safety', app(\App\Domain\Ops\Guardian\GuardianView::class)->for(request()->user()))
