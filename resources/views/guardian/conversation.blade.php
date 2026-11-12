{{-- One conversation, read by a guardian: the messages, report on each, and a reply. Data, forms and routes are the
     safety domain's (ConversationController::show, messages.store, messages.report). --}}
@php
    $tz = config('tutor.timezone');
    $title = $conversation['subject'] ?: ucfirst($conversation['context']).' conversation';
    $roleWord = ['guardian' => ' (guardian)', 'operator' => ' (Dot.Tutor)'];
@endphp
<x-layouts::learner :title="$title">
    <x-td.page-head :title="$title" :lede="'In this conversation: '.collect($conversation['people'])->map(fn ($p) => $p['name'].($roleWord[$p['role']] ?? ''))->join(', ').'.'">
        <x-slot:actions><x-td.button :href="route('safety.conversations.index')" icon="arrow-left" variant="quiet">All messages</x-td.button></x-slot:actions>
    </x-td.page-head>

    <div class="grid gap-6">
        @if ($conversation['notice'])
            <x-td.notice tone="info">{{ $conversation['notice'] }}</x-td.notice>
        @endif

        <x-td.panel title="Messages" flush>
            @if ($messages === [])
                <x-td.state kind="empty" title="No messages yet" />
            @else
                <ol class="ops-work gd-thread" aria-live="polite">
                    @foreach ($messages as $message)
                        <li>
                            <p class="ops-work__meta">{{ $message['sender']['name'] ?? 'Deleted account' }} · <time datetime="{{ $message['sent_at'] }}">{{ \Illuminate\Support\Carbon::parse($message['sent_at'])->timezone($tz)->format('j M, H:i') }}</time></p>
                            @if ($message['hidden'] && $message['body'] === null)
                                <p class="m-0 ops-muted">{{ $message['hidden_note'] }}</p>
                            @else
                                <p class="m-0" style="white-space:pre-line">{{ $message['body'] }}</p>
                                @if ($message['redacted'])<p class="m-0 ops-muted text-sm">{{ $message['redaction_note'] }}</p>@endif
                                @foreach ($message['attachments'] as $attachment)
                                    <p class="m-0"><a class="td-link" href="{{ $attachment['url'] }}">{{ $attachment['name'] }}</a> <span class="ops-muted text-sm">{{ max(1, (int) round($attachment['size_bytes'] / 1024)) }} KB</span></p>
                                @endforeach
                            @endif
                            @unless ($message['mine'])
                                <details>
                                    <summary class="cursor-pointer text-sm">Report this message</summary>
                                    <form method="POST" action="{{ route('safety.messages.report', $message['id']) }}" class="grid gap-3 mt-3">
                                        @csrf
                                        <x-td.field label="What is wrong?" for="category-{{ $message['id'] }}">
                                            <x-td.select name="category" id="category-{{ $message['id'] }}" :options="collect(\App\Domain\Safety\Enums\ReportCategory::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all()" required />
                                        </x-td.field>
                                        <x-td.field label="Anything we should know" for="details-{{ $message['id'] }}" optional><x-td.textarea name="details" id="details-{{ $message['id'] }}" rows="3" maxlength="2000" /></x-td.field>
                                        <div><x-td.button type="submit" icon="flag">Send report</x-td.button></div>
                                    </form>
                                </details>
                            @endunless
                        </li>
                    @endforeach
                </ol>
            @endif
        </x-td.panel>

        @if (! $conversation['closed'] && $conversation['your_role'] !== 'operator_reader')
            <x-td.panel title="Reply">
                <form method="POST" action="{{ route('safety.conversations.messages.store', $conversation['id']) }}" enctype="multipart/form-data" class="grid gap-4">
                    @csrf
                    <x-td.field label="Your message" for="reply"><x-td.textarea name="body" id="reply" rows="4" maxlength="{{ config('safety.messaging.max_length') }}" /></x-td.field>
                    <x-td.field label="Attach files" for="files" hint="PDF, PNG, JPEG or text." optional><input id="files" class="td-input" type="file" name="attachments[]" multiple accept=".pdf,.png,.jpg,.jpeg,.txt" aria-describedby="files-hint"></x-td.field>
                    <div><x-td.button type="submit" variant="primary">Send</x-td.button></div>
                </form>
            </x-td.panel>
        @endif
    </div>
</x-layouts::learner>
