{{-- Safety's conversation page. A guardian reads it in the guardian frame (D-2105); everyone else gets safety's own
     page, unchanged. Same data, same reply and report forms and routes. --}}
@if (app(\App\Domain\Ops\Guardian\GuardianShell::class)->applies())
    @include('guardian.conversation', ['conversation' => $conversation, 'messages' => $messages])
@else
    {!! view()->file(app_path('Domain/Safety/resources/views/conversation.blade.php'), ['conversation' => $conversation, 'messages' => $messages])->render() !!}
@endif
