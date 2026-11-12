{{-- Notification settings (R-47 comms). Channels say what they can really do on this host; mandatory kinds are facts, not switches. --}}
@php
    $statusTone = fn (array $c) => $c['available'] ? 'success' : 'neutral';
    $minorNote = match ($age_band) {
        'child' => 'Because you are under 13, notices reach you here in the app only. Your parent or guardian gets copies by email and push about your lessons, payments and safety.',
        'teen' => 'Because you are under 18, your parent or guardian also gets copies of messages about your lessons, payments and safety.',
        default => null,
    };
@endphp
<x-dynamic-component :component="'layouts::'.$shell" title="Notification settings">
    <x-td.page-head title="Notification settings" lede="Choose how Dot.Tutor reaches you. Everything also appears in your notifications here.">
        <x-slot:actions>
            <x-td.button :href="route('comms.inbox')" variant="quiet" icon="inbox">Your notifications</x-td.button>
        </x-slot:actions>
    </x-td.page-head>

    @if ($minorNote)
        <x-td.notice tone="info" class="mb-5">{{ $minorNote }}</x-td.notice>
    @endif

    <form method="POST" action="{{ route('comms.preferences.update') }}" class="grid gap-6">
        @csrf
        @method('PUT')

        <x-td.panel title="What you hear about, and where">
            <ul class="m-0 list-none p-0" aria-label="Notification choices by kind of message">
                @foreach ($categories as $key => $category)
                    <li class="grid gap-x-6 gap-y-3 border-b border-td-line py-4 first:pt-0 last:border-b-0 last:pb-0 sm:grid-cols-[minmax(0,1fr)_14rem]">
                        <div class="min-w-0">
                            <p class="m-0 font-semibold text-td-ink" id="cat-{{ $key }}">{{ $category['label'] }}</p>
                            <p class="m-0 max-w-[60ch] text-[0.8125rem] text-td-ink-2">{{ $category['description'] }}</p>
                        </div>
                        @if ($category['mandatory'])
                            <p class="m-0 flex items-start gap-2 text-[0.8125rem] text-td-ink">
                                <x-td.icon name="lock" />
                                <span><strong class="block font-semibold">Always on</strong>{{ $category['mandatory_reason'] }}</span>
                            </p>
                        @else
                            <div class="flex flex-wrap gap-x-6 gap-y-2" role="group" aria-labelledby="cat-{{ $key }}">
                                @foreach (['email' => 'Email', 'push' => 'Push'] as $channel => $label)
                                    @isset($category['channels'][$channel])
                                        <input type="hidden" name="shown[]" value="{{ $key }}.{{ $channel }}">
                                        <label class="td-check" for="pref-{{ $key }}-{{ $channel }}">
                                            <input type="checkbox" id="pref-{{ $key }}-{{ $channel }}" name="on[]" value="{{ $key }}.{{ $channel }}" @checked($category['channels'][$channel]['enabled'])>
                                            <span>{{ $label }}<span class="td-sr"> for {{ $category['label'] }}</span></span>
                                        </label>
                                    @endisset
                                @endforeach
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-td.panel>

        <x-td.panel title="Quiet hours">
            <p class="m-0 mb-4 max-w-[68ch] text-td-ink">Push notifications wait until quiet hours end. Email is never held, and safeguarding alerts are never held.</p>
            <input type="hidden" name="quiet_hours[enabled]" value="0">
            <x-td.check name="quiet_hours[enabled]" id="quiet-enabled" value="1" :checked="$quiet_hours['enabled']">Hold push notifications during quiet hours</x-td.check>
            <div class="mt-4 grid gap-4 sm:grid-cols-3">
                <x-td.field label="From" for="quiet-start">
                    <x-td.input type="time" name="quiet_hours[start]" id="quiet-start" :value="$quiet_hours['start']" required />
                </x-td.field>
                <x-td.field label="Until" for="quiet-end">
                    <x-td.input type="time" name="quiet_hours[end]" id="quiet-end" :value="$quiet_hours['end']" required />
                </x-td.field>
                <x-td.field label="Time zone" for="quiet-timezone" :hint="$quiet_hours['timezone_source'] === 'yours' ? null : 'Using '.$quiet_hours['timezone'].' until you choose.'">
                    <x-td.select name="quiet_hours[timezone]" id="quiet-timezone" :hint="$quiet_hours['timezone_source'] !== 'yours'"
                        :options="collect($timezones)->mapWithKeys(fn ($tz) => [$tz => str_replace('_', ' ', $tz)])->all()"
                        :value="$quiet_hours['timezone']" />
                </x-td.field>
            </div>
        </x-td.panel>

        <div><x-td.button type="submit" variant="primary" icon="check">Save notification settings</x-td.button></div>
    </form>

    <div class="td-panels mt-6">
        <x-td.panel title="Channels on Dot.Tutor today">
            <dl class="m-0 grid gap-3">
                @foreach (['in_app' => 'In the app', 'email' => 'Email', 'push' => 'Push notifications', 'sms' => 'Text messages (SMS)'] as $key => $label)
                    <div class="grid gap-1 sm:grid-cols-[12rem_minmax(0,1fr)]">
                        <dt class="font-semibold text-td-ink">{{ $label }}</dt>
                        <dd class="m-0 max-w-[68ch] text-td-ink">
                            <x-td.pill>{{ $channels[$key]['available'] ? 'Working' : 'Not available' }}</x-td.pill>
                            <span class="ml-1">{{ $channels[$key]['message'] }}</span>
                        </dd>
                    </div>
                @endforeach
            </dl>
        </x-td.panel>

        <x-td.panel title="Push on this device">
            @if ($push_key)
                <div data-comms-push data-key="{{ $push_key }}" data-subscribe="{{ route('comms.push.subscribe') }}" data-test="{{ route('comms.push.test') }}" data-csrf="{{ csrf_token() }}">
                    <p class="m-0 mb-3 text-td-ink" data-comms-push-status role="status">Checking whether this browser can receive push notifications.</p>
                    <div class="flex flex-wrap gap-2">
                        <x-td.button variant="primary" icon="bell" data-comms-push-on hidden>Turn on push here</x-td.button>
                        <x-td.button icon="x" data-comms-push-off hidden>Turn off push here</x-td.button>
                        <x-td.button variant="quiet" data-comms-push-test hidden>Send me a test</x-td.button>
                    </div>
                    <noscript><p class="m-0 mt-3 text-td-ink-2">Turning push on needs JavaScript in this browser.</p></noscript>
                </div>
                <script type="module" src="{{ asset('comms/push.js') }}"></script>
            @else
                <p class="m-0 max-w-[68ch] text-td-ink">{{ $channels['push']['message'] }}</p>
            @endif
            @if ($devices !== [])
                <h3 class="mb-1 mt-4 text-sm font-semibold text-td-ink">Devices with push on</h3>
                <ul class="m-0 list-none p-0 text-td-ink">
                    @foreach ($devices as $device)
                        <li>{{ $device['label'] }}</li>
                    @endforeach
                </ul>
            @endif
        </x-td.panel>
    </div>
</x-dynamic-component>
