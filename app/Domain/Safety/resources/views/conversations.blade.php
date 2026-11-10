<x-app-layout>
<x-safety::frame>
    <h1>Messages</h1>
    <p class="lede">Every conversation belongs to a booking, a room or a support request. Parents and guardians can read conversations with learners under 18.</p>

    @forelse ($conversations as $conversation)
        <div class="row">
            <a href="{{ route('safety.conversations.show', $conversation['id']) }}">{{ $conversation['subject'] ?: ucfirst($conversation['context']).' conversation' }}</a>
            <span class="muted">{{ collect($conversation['people'])->pluck('name')->join(', ') ?: 'Dot.Tutor support' }}
                @if ($conversation['unread'] > 0) · <strong>{{ $conversation['unread'] }} new</strong>@endif</span>
        </div>
    @empty
        <p class="muted">No conversations yet. You can message a tutor from a booking.</p>
    @endforelse

    <form method="POST" action="{{ route('safety.conversations.store') }}" class="panel">
        @csrf
        <input type="hidden" name="context_type" value="support">
        <h2 style="margin-top:0">Ask Dot.Tutor support</h2>
        <label for="support-subject">Subject</label>
        <input id="support-subject" type="text" name="subject" maxlength="160">
        <label for="support-body">Your message</label>
        <textarea id="support-body" name="body" required maxlength="{{ config('safety.messaging.max_length') }}"></textarea>
        <button type="submit">Send to support</button>
    </form>
</x-safety::frame>
</x-app-layout>
