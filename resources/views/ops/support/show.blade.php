@php $tz = \App\Domain\Student\Support\Times::zone(); @endphp
<x-layouts::operations :title="$conversation->subject ?: 'Support request '.$conversation->id">
    <x-td.page-head :title="$conversation->subject ?: 'Support request '.$conversation->id" lede="Opening this conversation is recorded in the audit log.">
        <x-slot:actions><x-td.button :href="route('ops.support')" icon="arrow-left" variant="quiet">Support</x-td.button></x-slot:actions>
    </x-td.page-head>
    <div class="grid gap-6">
        @if ($conversation->involves_minor)
            <x-td.notice tone="info">A minor is in this conversation. Their guardians read it, and contact details in any message are hidden automatically.</x-td.notice>
        @endif
        <x-td.panel>
            @if ($messages->isEmpty())
                <p class="m-0 ops-muted">No messages yet.</p>
            @else
                <ol class="ops-thread">
                    @foreach ($messages as $msg)
                        <li @class(['ops-thread--staff' => $msg->sender?->is_platform_operator])>
                            <p class="ops-thread__meta">{{ $msg->sender?->name ?? 'Deleted account' }}{{ $msg->sender?->is_platform_operator ? ' (Dot.Tutor)' : '' }} · {{ $msg->created_at->timezone($tz)->format('j M H:i') }}{{ $msg->redactions ? ' · contact details hidden' : '' }}</p>
                            <p class="m-0 whitespace-pre-line">{{ $msg->hidden_at ? 'A moderator removed this message.' : $msg->body }}</p>
                        </li>
                    @endforeach
                </ol>
            @endif
        </x-td.panel>
        @if ($canReply && ! $conversation->closed_at)
            <x-td.panel title="Reply">
                <form method="POST" action="{{ route('ops.support.reply', $conversation) }}" class="ops-act ops-act--stack">
                    @csrf
                    <x-td.field label="Your reply" for="reply-body"><x-td.textarea name="body" id="reply-body" rows="4" required /></x-td.field>
                    <div><x-td.button type="submit" variant="primary">Send reply</x-td.button></div>
                </form>
            </x-td.panel>
        @endif
    </div>
</x-layouts::operations>
