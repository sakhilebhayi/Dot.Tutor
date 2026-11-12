{{-- Ops restyle of safety's guardian dashboard page (GET /safety/guardian): the same guardian home, with the money
     orders added from the guardian view service. JSON callers of that route are unaffected. --}}
@include('guardian.home', app(\App\Domain\Ops\Guardian\GuardianView::class)->for(request()->user()))
