<x-app-layout>
<x-safety::frame>
    <p><a href="{{ route('safety.conversations.index') }}">All messages</a></p>
    <h1>{{ $conversation['subject'] ?: ucfirst($conversation['context']).' conversation' }}</h1>
    <p class="muted">With {{ collect($conversation['people'])->map(fn ($p) => $p['name'].($p['role'] === 'guardian' ? ' (guardian)' : ($p['role'] === 'operator' ? ' (Dot.Tutor)' : '')))->join(', ') }}</p>

    @if ($conversation['notice'])<div class="notice" role="note">{{ $conversation['notice'] }}</div>@endif

    <div aria-live="polite">
    @forelse ($messages as $message)
        <article class="msg">
            <div class="muted">{{ $message['sender']['name'] ?? 'Deleted account' }} · {{ \Illuminate\Support\Carbon::parse($message['sent_at'])->timezone(config('tutor.timezone'))->format('j M, H:i') }}</div>
            @if ($message['hidden'] && $message['body'] === null)
                <p class="muted">{{ $message['hidden_note'] }}</p>
            @else
                <div class="msg-body">{{ $message['body'] }}</div>
                @if ($message['redacted'])<p class="muted">{{ $message['redaction_note'] }}</p>@endif
                @foreach ($message['attachments'] as $attachment)
                    <div><a href="{{ $attachment['url'] }}">{{ $attachment['name'] }}</a> <span class="muted">{{ number_format($attachment['size_bytes'] / 1024) }} KB</span></div>
                @endforeach
            @endif
            @unless ($message['mine'])
                <details>
                    <summary class="muted">Report this message</summary>
                    <form method="POST" action="{{ route('safety.messages.report', $message['id']) }}">
                        @csrf
                        <label for="category-{{ $message['id'] }}">What is wrong?</label>
                        <select id="category-{{ $message['id'] }}" name="category" required>
                            @foreach (\App\Domain\Safety\Enums\ReportCategory::cases() as $category)
                                <option value="{{ $category->value }}">{{ $category->label() }}</option>
                            @endforeach
                        </select>
                        <label for="details-{{ $message['id'] }}">Anything we should know (optional)</label>
                        <textarea id="details-{{ $message['id'] }}" name="details" maxlength="2000"></textarea>
                        <button type="submit" class="secondary">Send report</button>
                    </form>
                </details>
            @endunless
        </article>
    @empty
        <p class="muted">No messages yet.</p>
    @endforelse
    </div>

    @if (! $conversation['closed'] && $conversation['your_role'] !== 'operator_reader')
        <form method="POST" action="{{ route('safety.conversations.messages.store', $conversation['id']) }}" enctype="multipart/form-data" class="panel">
            @csrf
            <label for="reply">Reply</label>
            <textarea id="reply" name="body" maxlength="{{ config('safety.messaging.max_length') }}"></textarea>
            <label for="files">Attach files (PDF, PNG, JPEG or text, optional)</label>
            <input id="files" type="file" name="attachments[]" multiple accept=".pdf,.png,.jpg,.jpeg,.txt">
            <button type="submit">Send</button>
        </form>
    @endif
</x-safety::frame>
</x-app-layout>
