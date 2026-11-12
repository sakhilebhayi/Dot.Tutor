{{-- Top-bar link to the notifications inbox with the unread count (one per subject, capped "9+").
     For the shells: <x-comms::bell /> beside the account link. Renders nothing for guests. --}}
@auth
    @php $count = \App\Domain\Comms\Inbox::unreadCount(auth()->user()); $badge = \App\Domain\Comms\Inbox::badge($count); @endphp
    <a href="{{ route('comms.inbox') }}" {{ $attributes->class(['td-btn', 'td-btn--quiet', 'td-btn--icon', 'relative']) }}
        aria-label="{{ $count === 0 ? 'Notifications, none unread' : 'Notifications, '.$count.' unread' }}">
        <x-td.icon name="bell" />
        @if ($badge)
            <span class="absolute -right-1 -top-1 min-w-5 rounded-full bg-td-primary px-1 text-center text-[0.6875rem] font-bold leading-5 text-td-primary-ink" aria-hidden="true">{{ $badge }}</span>
        @endif
    </a>
@endauth
