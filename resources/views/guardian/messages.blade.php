{{-- A guardian's messages: every conversation they are in (their children's lessons, rooms and support), and the
     support form. Data and form from the safety domain's ConversationController. --}}
@php($ctx = ['booking' => 'Lesson', 'room' => 'Study room', 'support' => 'Support', 'direct' => 'Direct'])
<x-layouts::learner title="Messages">
    <x-td.page-head title="Messages" lede="You are in every conversation your child has with a tutor, a study room or support. Read, reply, and report anything that worries you." />

    <div class="grid gap-6">
        <x-td.panel title="Conversations" flush>
            @if ($conversations === [])
                <x-td.state kind="empty" title="No conversations yet">When your child messages a tutor about a lesson, or writes to support, the conversation appears here.</x-td.state>
            @else
                <ul class="ops-work">
                    @foreach ($conversations as $conversation)
                        <li>
                            <div class="grid gap-1">
                                <p class="ops-work__title"><a href="{{ route('safety.conversations.show', $conversation['id']) }}">{{ $conversation['subject'] ?: ($ctx[$conversation['context']] ?? 'A').' conversation' }}</a></p>
                                <p class="ops-work__meta">{{ $ctx[$conversation['context']] ?? ucfirst($conversation['context']) }} · {{ collect($conversation['people'])->pluck('name')->join(', ') ?: 'Dot.Tutor support' }}@if ($conversation['last_message_at']) · <time datetime="{{ $conversation['last_message_at'] }}">{{ \Illuminate\Support\Carbon::parse($conversation['last_message_at'])->timezone(config('tutor.timezone'))->format('j M, H:i') }}</time>@endif</p>
                            </div>
                            @if ($conversation['unread'] > 0)
                                @include('ops.partials.tag', ['text' => $conversation['unread'].' new', 'tone' => 'info', 'icon' => 'message-square'])
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-td.panel>

        <x-td.panel title="Ask Dot.Tutor support" id="support">
            <form method="POST" action="{{ route('safety.conversations.store') }}" class="grid gap-4">
                @csrf
                <input type="hidden" name="context_type" value="support">
                <x-td.field label="Subject" for="support-subject" optional><x-td.input name="subject" id="support-subject" maxlength="160" /></x-td.field>
                <x-td.field label="Your message" for="support-body"><x-td.textarea name="body" id="support-body" rows="4" required maxlength="{{ config('safety.messaging.max_length') }}" /></x-td.field>
                <div><x-td.button type="submit" variant="primary" icon="life-buoy">Send to support</x-td.button></div>
            </form>
        </x-td.panel>
    </div>
</x-layouts::learner>
