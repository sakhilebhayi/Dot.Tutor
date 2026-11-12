{{-- One child, for their guardian (brief section 15): decisions, lessons, purchases, messages, reports and notices,
     settings. Everything posts to the safety domain's guardian endpoints, which check the link server-side. --}}
@php
    use App\Domain\Money\Money;
    $tz = config('tutor.timezone');
    $c = $child;
    $kinds = ['booking' => 'Booking a lesson', 'purchase' => 'Buying a course, lesson package or material', 'paid_room' => 'Joining a paid study room', 'live_lesson' => 'A live one-to-one lesson'];
    $orderText = ['paid' => 'Paid', 'partially_refunded' => 'Partly refunded', 'refunded' => 'Refunded', 'pending_payment' => 'Not paid yet', 'awaiting_guardian' => 'Waiting for you', 'cancelled' => 'Cancelled', 'expired' => 'Expired', 'guardian_refused' => 'You declined'];
@endphp
<x-layouts::learner :title="$c['name']">
    <x-td.page-head :title="$c['name']" :lede="$c['age_band_label'].', your '.str_replace('_', ' ', $c['relationship']).'.'">
        <x-slot:actions><x-td.button :href="route('guardian.home')" icon="arrow-left" variant="quiet">All children</x-td.button></x-slot:actions>
    </x-td.page-head>

    <div class="grid gap-6">
        @if ($c['account']['status'] === 'suspended')
            <x-td.notice tone="danger" title="{{ $c['name'] }}'s account is suspended">They cannot book, pay, message or join rooms{{ $c['account']['suspended_until'] ? ' until '.\Illuminate\Support\Carbon::parse($c['account']['suspended_until'])->timezone($tz)->format('j M Y') : '' }}. The reason is under Safety notices below; contact support if you have questions.</x-td.notice>
        @elseif ($c['account']['status'] === 'limited')
            <x-td.notice tone="warning" title="Consent is not recorded">{{ $c['name'] }} can only read, report and ask support for help until consent is given.</x-td.notice>
        @endif
        @unless ($c['birth_date_known'])
            <x-td.notice tone="info">No date of birth is recorded for {{ $c['name'] }}, so Dot.Tutor treats them by the strictest rules it can.</x-td.notice>
        @endunless

        <x-td.panel title="Waiting for you" flush>
            @forelse ($c['pending'] as $approval)
                @include('guardian._decision', ['approval' => $approval, 'childName' => $c['name']])
            @empty
                <x-td.state kind="success" title="Nothing is waiting for your approval" />
            @endforelse
        </x-td.panel>

        <x-td.panel title="Upcoming lessons" flush>
            @if ($c['upcoming_lessons'] === [])
                <x-td.state kind="empty" title="No lessons booked">Lessons {{ $c['name'] }} books appear here once you approve them.</x-td.state>
            @else
                <ul class="ops-work">
                    @foreach ($c['upcoming_lessons'] as $lesson)
                        <li>
                            <div>
                                <p class="ops-work__title">{{ $lesson['subject'] ?? 'Lesson' }} with {{ $lesson['tutor'] ?? 'a tutor' }}</p>
                                <p class="ops-work__meta">{{ \Illuminate\Support\Carbon::parse($lesson['starts_at'])->timezone($tz)->format('l j F, H:i') }} · {{ $lesson['duration_minutes'] }} minutes</p>
                            </div>
                            @include('ops.partials.tag', ['text' => ucfirst(str_replace('_', ' ', $lesson['status'] === 'pending' ? 'requested' : $lesson['status'])), 'tone' => $lesson['status'] === 'confirmed' ? 'success' : 'neutral'])
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-td.panel>

        <x-td.panel title="Purchases" flush>
            @if ($c['orders'] === [] && $c['purchases'] === [])
                <x-td.state kind="empty" title="No purchases">Anything {{ $c['name'] }} wants to buy asks you first.</x-td.state>
            @else
                <ul class="ops-work">
                    @foreach ($c['orders'] as $o)
                        <li>
                            <div>
                                <p class="ops-work__title">{{ $o['items'] ?: 'Order' }}</p>
                                <p class="ops-work__meta">{{ $o['created_at']->timezone($tz)->format('j M Y') }} · {{ Money::of((int) $o['total_minor'], $o['currency'])->format() }}{{ $o['refunded_minor'] > 0 ? ', '.Money::of((int) $o['refunded_minor'], $o['currency'])->format().' refunded' : '' }}{{ $o['paid_by_guardian'] ? ' · paid by a guardian' : '' }}</p>
                            </div>
                            @include('ops.partials.tag', ['text' => $orderText[$o['status']] ?? ucfirst($o['status']), 'tone' => in_array($o['status'], ['paid'], true) ? 'success' : ($o['status'] === 'awaiting_guardian' ? 'warning' : 'neutral')])
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-td.panel>

        <div class="td-panels td-panels--2">
            <x-td.panel title="Messages">
                <p class="mt-0">{{ $c['messages']['conversations'] }} {{ $c['messages']['conversations'] === 1 ? 'conversation' : 'conversations' }}, {{ $c['messages']['unread_for_you'] }} unread by you.
                    @if ($c['messages']['redacted_messages'] > 0) In {{ $c['messages']['redacted_messages'] }} {{ $c['messages']['redacted_messages'] === 1 ? 'message' : 'messages' }} contact details were hidden automatically.@endif</p>
                <p class="ops-muted text-sm">You are in every conversation {{ $c['name'] }} has with a tutor, a room or support. You can read and reply, and report anything that worries you.</p>
                @if ($c['messages']['conversations'] > 0)
                    <x-td.button :href="route('safety.conversations.index')" icon="message-square">Read the messages</x-td.button>
                @endif
            </x-td.panel>

            <x-td.panel title="Reports and safety notices">
                @if ($c['reports'] === [] && $c['notices'] === [])
                    <p class="m-0 ops-muted">No reports and no warnings.</p>
                @else
                    <ul class="m-0 p-0 grid gap-3" style="list-style:none">
                        @foreach ($c['notices'] as $n)
                            <li>@include('ops.partials.tag', ['text' => $n['kind'] === 'suspension' ? 'Suspension' : 'Warning', 'tone' => $n['kind'] === 'suspension' ? 'danger' : 'warning']) {{ $n['starts_at']?->timezone($tz)->format('j M Y') }}: {{ $n['reason'] }}</li>
                        @endforeach
                        @foreach ($c['reports'] as $r)
                            <li>{{ $r['category_label'] }} · {{ $r['filed_by_child'] ? 'reported by '.$c['name'] : 'in '.$c['name'].'\'s conversations' }} · {{ \Illuminate\Support\Carbon::parse($r['created_at'])->timezone($tz)->format('j M Y') }} · {{ $r['status'] === 'open' ? 'being looked at' : $r['status'] }}</li>
                        @endforeach
                    </ul>
                @endif
            </x-td.panel>
        </div>

        <x-td.panel title="Settings">
            <div class="grid gap-6">
                @if ($c['approval_settings'] !== null)
                    <form method="POST" action="{{ route('safety.guardian.links.settings', $c['link_id']) }}" class="grid gap-3">
                        @csrf
                        <fieldset class="m-0 p-0 border-0 grid gap-2">
                            <legend class="td-label mb-2">What needs your approval</legend>
                            <p class="m-0 text-sm ops-muted">{{ $c['name'] }} is 13 to 17, so you choose. With more than one guardian, the strictest choice applies.</p>
                            @foreach ($kinds as $kind => $label)
                                <input type="hidden" name="settings[{{ $kind }}]" value="0">
                                <x-td.check name="settings[{{ $kind }}]" id="set-{{ $kind }}" value="1" :checked="(bool) ($c['approval_settings'][$kind] ?? false)">{{ $label }}</x-td.check>
                            @endforeach
                        </fieldset>
                        <div><x-td.button type="submit">Save approval settings</x-td.button></div>
                    </form>
                @else
                    <p class="m-0">{{ $c['name'] }} is under 13, so every booking, purchase, paid room and live one-to-one lesson needs your approval. This cannot be switched off.</p>
                @endif

                <form method="POST" action="{{ route('safety.guardian.links.recording', $c['link_id']) }}" class="grid gap-2">
                    @csrf
                    <input type="hidden" name="consent" value="{{ $c['recording_consent'] ? 0 : 1 }}">
                    <p class="m-0"><span class="td-label">Lesson recordings</span><br>{{ $c['recording_consent'] ? 'You allow live lessons to be recorded.' : 'Live lessons with '.$c['name'].' are not recorded.' }}</p>
                    <div><x-td.button type="submit" :variant="$c['recording_consent'] ? 'quiet' : 'secondary'">{{ $c['recording_consent'] ? 'Stop allowing recordings' : 'Allow recordings' }}</x-td.button></div>
                </form>

                <details>
                    <summary class="cursor-pointer">Stop being {{ $c['name'] }}'s guardian on Dot.Tutor</summary>
                    <form method="POST" action="{{ route('safety.guardian.links.revoke', $c['link_id']) }}" class="grid gap-3 mt-3">
                        @csrf
                        <p class="m-0">Your consent is withdrawn and you leave their conversations. Until another guardian consents, {{ $c['name'] }} can only read, report and ask support for help.</p>
                        <x-td.field label="Reason" for="revoke-reason" optional><x-td.input name="reason" id="revoke-reason" maxlength="255" /></x-td.field>
                        <div><x-td.button type="submit" variant="danger">Remove the link</x-td.button></div>
                    </form>
                </details>
            </div>
        </x-td.panel>
    </div>
</x-layouts::learner>
