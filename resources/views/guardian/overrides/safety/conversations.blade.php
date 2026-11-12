{{-- Safety's messages list. A guardian sees it in the guardian frame (D-2105); everyone else gets safety's own page,
     unchanged. Same data, same support form fields and route. --}}
@if (app(\App\Domain\Ops\Guardian\GuardianShell::class)->applies())
    @include('guardian.messages', ['conversations' => $conversations])
@else
    {!! view()->file(app_path('Domain/Safety/resources/views/conversations.blade.php'), ['conversations' => $conversations])->render() !!}
@endif
