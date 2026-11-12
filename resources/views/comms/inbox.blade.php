{{-- Notifications inbox (R-47 comms). Real records only; each one says what happened to its email and push copies. --}}
<x-dynamic-component :component="'layouts::'.$shell" title="Notifications">
    <x-td.page-head title="Notifications" lede="Lessons, payments, approvals and anything else Dot.Tutor told you, newest first.">
        <x-slot:actions>
            @if ($unread > 0)
                <form method="POST" action="{{ route('comms.inbox.read-all') }}">
                    @csrf
                    <x-td.button type="submit" icon="check">Mark all as read</x-td.button>
                </form>
            @endif
            <x-td.button :href="route('comms.preferences')" variant="quiet" icon="settings">Notification settings</x-td.button>
        </x-slot:actions>
    </x-td.page-head>

    <x-td.tabs label="Which notifications" class="mb-5" :items="[
        ['label' => 'All', 'href' => route('comms.inbox'), 'current' => ! $onlyUnread],
        ['label' => 'Unread', 'href' => route('comms.inbox', ['unread' => 1]), 'current' => $onlyUnread, 'count' => $unread],
    ]" />

    @if ($items === [])
        <x-td.state kind="empty" :title="$onlyUnread ? 'Nothing unread' : 'No notifications yet'">
            {{ $onlyUnread ? 'You have read everything.' : 'When a tutor answers a request, a lesson is coming up or a payment goes through, it appears here.' }}
        </x-td.state>
    @else
        <ol class="comms-list m-0 list-none border-t border-td-line p-0" aria-label="Notifications">
            @foreach ($items as $item)
                <li @class(['comms-item grid gap-x-5 gap-y-2 border-b border-td-line py-4 sm:grid-cols-[minmax(0,1fr)_auto]', 'comms-item--unread' => ! $item['read']])>
                    <div class="min-w-0">
                        <p class="m-0 flex flex-wrap items-center gap-x-3 gap-y-1 text-[0.8125rem] text-td-ink-2">
                            @unless ($item['read'])
                                <span class="inline-flex items-center gap-1.5 font-semibold text-td-primary">
                                    <span class="inline-block size-2 rounded-full bg-td-primary" aria-hidden="true"></span>Unread
                                </span>
                            @endunless
                            <span>{{ $item['category_label'] }}</span>
                            @if ($item['created_at'])
                                <time datetime="{{ $item['created_at'] }}" title="{{ \App\Domain\Student\Support\Times::stamp($item['created_at'], 'j F Y, H:i') }}">
                                    {{ \Illuminate\Support\Carbon::parse($item['created_at'])->diffForHumans() }}
                                </time>
                            @endif
                        </p>
                        {{-- The title is the way in (R-47 polish D-2022, critic A 24: seven solid green "Open" buttons
                             competed). It submits the row's form, which marks it read and goes to the page. --}}
                        <h2 @class(['m-0 mt-1 text-base leading-snug text-td-ink text-pretty', 'font-bold' => ! $item['read'], 'font-semibold' => $item['read']])>
                            @if ($item['url'])
                                <button type="submit" form="comms-open-{{ $item['id'] }}" class="comms-item__open">{{ $item['title'] }}<x-td.icon name="arrow-right" /></button>
                            @else
                                {{ $item['title'] }}
                            @endif
                        </h2>
                        @if ($item['message'] !== '')
                            <p class="m-0 mt-1 max-w-[68ch] text-td-ink text-pretty">{{ $item['message'] }}</p>
                        @endif
                        @if ($item['expired'])
                            <p class="m-0 mt-1 text-[0.8125rem] text-td-ink-2">This has passed.</p>
                        @endif
                        @if ($item['deliveries'] !== [])
                            <details class="mt-2 text-[0.8125rem] text-td-ink-2">
                                <summary class="cursor-pointer select-none underline underline-offset-4">How this reached you</summary>
                                <ul class="m-0 mt-1 list-none p-0">
                                    <li>In the app</li>
                                    @foreach ($item['deliveries'] as $delivery)
                                        <li>{{ $delivery['label'] }}</li>
                                    @endforeach
                                </ul>
                            </details>
                        @endif
                    </div>
                    <div class="flex flex-wrap items-start gap-2 sm:justify-end">
                        @if ($item['url'])
                            <form method="POST" action="{{ route('comms.inbox.read', $item['id']) }}" id="comms-open-{{ $item['id'] }}" hidden>
                                @csrf
                                <input type="hidden" name="open" value="1">
                            </form>
                        @endif
                        @if ($item['calendar_url'] && ! $item['expired'])
                            <x-td.button :href="$item['calendar_url']" size="sm" variant="quiet" icon="calendar">Add to calendar</x-td.button>
                        @endif
                        @unless ($item['read'])
                            <form method="POST" action="{{ route('comms.inbox.read', $item['id']) }}">
                                @csrf
                                <x-td.button type="submit" size="sm" variant="quiet" icon="check">Mark as read</x-td.button>
                            </form>
                        @endunless
                    </div>
                </li>
            @endforeach
        </ol>

        @if ($nextUrl)
            <p class="mt-5"><x-td.button :href="$nextUrl" icon-end="arrow-right">Older notifications</x-td.button></p>
        @endif
    @endif
</x-dynamic-component>
