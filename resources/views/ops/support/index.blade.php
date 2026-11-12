@php $tz = config('tutor.timezone'); @endphp
<x-layouts::operations title="Support">
    <x-td.page-head title="Support" lede="Each support request is its own conversation. Minors can always reach support, even before a guardian consents; their guardians see the thread and contact details are hidden." />
    <x-td.panel flush>
        @if ($threads->isEmpty())
            <x-td.state kind="empty" title="No support conversations yet">Learners, guardians and tutors start one from their messages.</x-td.state>
        @else
            <ul class="ops-work">
                @foreach ($threads as $t)
                    <li>
                        <div>
                            <p class="ops-work__title"><a href="{{ route('ops.support.show', $t) }}">{{ $t->subject ?: 'Support request '.$t->id }}</a></p>
                            <p class="ops-work__meta">{{ $members($t) ?: 'No member left' }} · {{ $t->last_message_at ? 'last message '.$t->last_message_at->timezone($tz)->format('j M H:i') : 'no messages' }}{{ $t->involves_minor ? ' · a minor is in this conversation' : '' }}</p>
                        </div>
                        @if ($t->closed_at)
                            @include('ops.partials.tag', ['text' => 'Closed'])
                        @elseif ($waiting($t))
                            @include('ops.partials.tag', ['text' => 'Waiting for us', 'tone' => 'warning', 'icon' => 'hourglass'])
                        @else
                            @include('ops.partials.tag', ['text' => 'Answered', 'tone' => 'success'])
                        @endif
                    </li>
                @endforeach
            </ul>
            @if ($threads->hasPages())<div class="px-5 py-3"><x-td.pagination :paginator="$threads" label="Support pages" /></div>@endif
        @endif
    </x-td.panel>
</x-layouts::operations>
