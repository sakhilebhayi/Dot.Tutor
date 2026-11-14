{{-- Guardian home (brief section 15): an urgent safety matter first, then what waits for a decision, what was
     decided lately, and each child with a status that tells the truth. Phone-first. --}}
@php
    $tz = config('tutor.timezone');
    // The arrival's one primary action (R-50): an urgent safety matter, then what waits for a decision, then the
    // child's own page. No child is named on the arrival.
    if (! $is_guardian) {
        $primary = ['label' => 'Go to your learning', 'href' => route('learn.home'), 'icon' => 'graduation-cap'];
        $slip = ['label' => 'You are not linked to a learner', 'text' => 'A learner invites their parent or guardian from their account.'];
    } elseif ($urgent !== []) {
        $primary = ['label' => 'Read the urgent report', 'href' => route('safety.guardian.dashboard').'#'.$urgent[0]['key']];
        $slip = ['label' => 'Needs you now', 'text' => count($urgent) === 1 ? '1 urgent safety report about your child.' : count($urgent).' urgent safety reports.'];
    } elseif ($waiting_total > 0) {
        $primary = ['label' => $waiting_total === 1 ? 'Decide the request' : 'Decide the requests', 'href' => route('guardian.approvals')];
        $slip = ['label' => 'Waiting for you', 'text' => $waiting_total === 1 ? '1 request needs your approval.' : $waiting_total.' requests need your approval.'];
    } elseif (count($children) === 1) {
        $primary = ['label' => 'See your child\'s learning', 'href' => route('guardian.child', $children[0]['id'])];
        $slip = ['label' => 'Nothing is waiting', 'text' => 'No request needs your approval right now.'];
    } else {
        $primary = ['label' => 'See past decisions', 'href' => route('guardian.approvals')];
        $slip = ['label' => 'Nothing is waiting', 'text' => 'No request needs your approval right now.'];
    }
@endphp
<x-layouts::learner title="Children">
    <x-td.arrival :headline="$is_guardian ? 'Your children on Dot.Tutor' : 'Look after a learner on Dot.Tutor'"
        :lede="$is_guardian ? 'Approve or decline what your child asks for on Dot.Tutor, see their lessons, purchases and messages, and choose what needs your approval.' : 'On Dot.Tutor a parent or guardian looks after a learner under 18: they approve lessons, purchases and rooms, and see messages and reports.'"
        :primary="$primary" :slip="$slip" />

    @if (! $is_guardian)
        {{-- The arrival carries this page's one primary action, so the invitation button is quiet here. --}}
        @include('guardian.partials.not-linked', ['quietInvite' => true])
    @else
        <div class="grid gap-6">
            @foreach ($urgent as $row)
                @include('guardian.partials.urgent', ['row' => $row])
            @endforeach

            <x-td.panel title="Waiting for you" flush>
                @if ($waiting_total === 0)
                    <x-td.state kind="success" title="Nothing is waiting for your approval">When your child asks to book a lesson, buy something or join a paid room, it appears here and you get an email.</x-td.state>
                @else
                    @foreach ($children as $child)
                        @foreach ($child['pending'] as $approval)
                            @include('guardian._decision', ['approval' => $approval, 'childName' => $child['name']])
                        @endforeach
                    @endforeach
                @endif
            </x-td.panel>

            @if ($decisions !== [])
                <x-td.panel title="Recent decisions" flush>
                    @include('guardian.partials.decisions', ['decisions' => array_slice($decisions, 0, 5)])
                    @if (count($decisions) > 5)
                        <div class="px-5 py-4"><x-td.button :href="route('guardian.approvals')" variant="quiet" icon-end="arrow-right">All {{ count($decisions) }} decisions</x-td.button></div>
                    @endif
                </x-td.panel>
            @endif

            <x-td.panel title="{{ count($children) === 1 ? 'Your child' : 'Your children' }}" flush>
                <ul class="ops-work">
                    @foreach ($children as $child)
                        @php
                            $next = $child['upcoming_lessons'][0] ?? null;
                            $urgentCount = count($child['urgent']);
                            $tag = match (true) {
                                $urgentCount > 0 => ['text' => $urgentCount === 1 ? '1 urgent report' : $urgentCount.' urgent reports', 'tone' => 'danger', 'href' => route('safety.guardian.dashboard').'#'.$child['urgent'][0]['key']],
                                $child['account']['status'] === 'suspended' => ['text' => 'Suspended', 'tone' => 'danger'],
                                $child['account']['status'] === 'limited' => ['text' => 'Needs consent', 'tone' => 'warning'],
                                $child['open_reports'] > 0 => ['text' => $child['open_reports'] === 1 ? '1 open report' : $child['open_reports'].' open reports', 'tone' => 'warning', 'href' => route('safety.guardian.dashboard')],
                                count($child['pending']) > 0 => ['text' => count($child['pending']).' waiting for you', 'tone' => 'info'],
                                default => ['text' => 'Active', 'tone' => 'success'],
                            };
                        @endphp
                        <li>
                            <div class="grid gap-1">
                                <p class="ops-work__title"><a href="{{ route('guardian.child', $child['id']) }}">{{ $child['name'] }}</a> <span class="ops-muted" style="font-weight:400">· {{ $child['age_band_label'] }}</span></p>
                                <p class="ops-work__meta">{{ $next ? 'Next lesson: '.($next['subject'] ?? 'a lesson').' with '.($next['tutor'] ?? 'a tutor').', '.\Illuminate\Support\Carbon::parse($next['starts_at'])->timezone($tz)->format('D j M, H:i') : 'No lessons booked' }} · {{ $child['messages']['conversations'] }} {{ $child['messages']['conversations'] === 1 ? 'conversation' : 'conversations' }}</p>
                            </div>
                            @if (isset($tag['href']))
                                <a href="{{ $tag['href'] }}" class="no-underline">@include('ops.partials.tag', $tag)</a>
                            @else
                                @include('ops.partials.tag', $tag)
                            @endif
                        </li>
                    @endforeach
                </ul>
            </x-td.panel>
        </div>
    @endif
</x-layouts::learner>
