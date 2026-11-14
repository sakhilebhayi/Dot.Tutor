{{-- Guardian home (brief section 15): an urgent safety matter first, then what waits for a decision, what was
     decided lately, and each child with a status that tells the truth. Phone-first. --}}
@php
    $tz = config('tutor.timezone');
    // The head's one primary action (R-50): an urgent safety matter, then what waits for a decision, then the child's
    // own page. The headline says the same in a true sentence (R-51). No child is named in the head.
    if (! $is_guardian) {
        $primary = ['label' => 'Go to your learning', 'href' => route('learn.home'), 'icon' => 'graduation-cap'];
        $headline = 'You are not linked to a learner.';
        $sub = 'A learner invites their parent or guardian from their account.';
    } elseif ($urgent !== []) {
        $primary = ['label' => 'Read the urgent report', 'href' => route('safety.guardian.dashboard').'#'.$urgent[0]['key']];
        $headline = count($urgent) === 1 ? '1 urgent safety report about your child.' : count($urgent).' urgent safety reports about your child.';
        $sub = 'Read it first. Everything else can wait.';
    } elseif ($waiting_total > 0) {
        $primary = ['label' => $waiting_total === 1 ? 'Decide the request' : 'Decide the requests', 'href' => route('guardian.approvals')];
        $headline = $waiting_total === 1 ? '1 request needs your approval.' : $waiting_total.' requests need your approval.';
        $sub = 'Your child asked, and nothing is charged until you approve and someone pays. Nothing is urgent.';
    } elseif (count($children) === 1) {
        $primary = ['label' => 'See your child\'s learning', 'href' => route('guardian.child', $children[0]['id'])];
        $headline = 'Nothing is waiting for your approval.';
        $sub = 'No request needs a decision right now.';
    } else {
        $primary = ['label' => 'See past decisions', 'href' => route('guardian.approvals')];
        $headline = 'Nothing is waiting for your approval.';
        $sub = 'No request needs a decision right now.';
    }
    $toneMap = ['danger' => 'danger', 'warning' => 'warn', 'info' => 'info', 'success' => 'ok'];
@endphp
<x-layouts::learner title="Children">
    <div class="hm">
    <x-td.home-head :headline="$headline" :sub="$sub" :primary="$primary" />

    @if (! $is_guardian)
        {{-- The head carries this page's one primary action, so the invitation button is quiet here. --}}
        <div class="hm-gap">@include('guardian.partials.not-linked', ['quietInvite' => true])</div>
    @else
        <div class="hm-split">
            <section aria-labelledby="waiting-title">
                @foreach ($urgent as $row)
                    @include('guardian.partials.urgent', ['row' => $row])
                @endforeach

                <div class="hm-sech"><h2 id="waiting-title">Waiting for you</h2>@if ($waiting_total > 0)<span class="hm-sech__n">{{ $waiting_total }} {{ \Illuminate\Support\Str::plural('request', $waiting_total) }}</span>@endif</div>
                @if ($waiting_total === 0)
                    <div class="hm-empty">
                        <x-td.shelf />
                        <p>Nothing is waiting for your approval. When your child asks to book a lesson, buy something or join a paid room, it appears here and you get an email.</p>
                    </div>
                @else
                    <ul class="hm-rows">
                        @foreach ($children as $child)
                            @foreach ($child['pending'] as $approval)
                                <li class="hm-row hm-row--form">@include('guardian._decision', ['approval' => $approval, 'childName' => $child['name']])</li>
                            @endforeach
                        @endforeach
                    </ul>
                @endif

                @if ($decisions !== [])
                    <div class="hm-sech hm-gap"><h2>Recent decisions</h2><x-td.button :href="route('guardian.approvals')" variant="quiet" size="sm">All {{ count($decisions) }} {{ \Illuminate\Support\Str::plural('decision', count($decisions)) }}</x-td.button></div>
                    @include('guardian.partials.decisions', ['decisions' => array_slice($decisions, 0, 5)])
                @endif
            </section>

            <section aria-labelledby="children-title">
                <div class="hm-sech"><h2 id="children-title">{{ count($children) === 1 ? 'Your child' : 'Your children' }}</h2></div>
                <ul class="hm-rows">
                    @foreach ($children as $child)
                        @php
                            $next = $child['upcoming_lessons'][0] ?? null;
                            $urgentCount = count($child['urgent']);
                            $tag = match (true) {
                                $urgentCount > 0 => ['text' => $urgentCount === 1 ? '1 urgent report' : $urgentCount.' urgent reports', 'tone' => 'danger', 'icon' => 'circle-alert', 'href' => route('safety.guardian.dashboard').'#'.$child['urgent'][0]['key']],
                                $child['account']['status'] === 'suspended' => ['text' => 'Suspended', 'tone' => 'danger', 'icon' => 'circle-alert'],
                                $child['account']['status'] === 'limited' => ['text' => 'Needs consent', 'tone' => 'warning', 'icon' => 'triangle-alert'],
                                $child['open_reports'] > 0 => ['text' => $child['open_reports'] === 1 ? '1 open report' : $child['open_reports'].' open reports', 'tone' => 'warning', 'icon' => 'triangle-alert', 'href' => route('safety.guardian.dashboard')],
                                count($child['pending']) > 0 => ['text' => count($child['pending']).' waiting for you', 'tone' => 'info', 'icon' => 'clock'],
                                default => ['text' => 'Active', 'tone' => 'success', 'icon' => 'circle-check'],
                            };
                        @endphp
                        <li class="hm-row">
                            <div>
                                <a class="hm-row__link" href="{{ route('guardian.child', $child['id']) }}">{{ $child['name'] }}</a>
                                <span class="hm-row__meta">{{ $child['age_band_label'] }} · {{ $next ? 'Next lesson: '.($next['subject'] ?? 'a lesson').' with '.($next['tutor'] ?? 'a tutor').', '.\Illuminate\Support\Carbon::parse($next['starts_at'])->timezone($tz)->format('D j M, H:i') : 'No lessons booked' }} · {{ $child['messages']['conversations'] }} {{ $child['messages']['conversations'] === 1 ? 'conversation' : 'conversations' }}</span>
                            </div>
                            <div class="hm-row__end">
                                @if (isset($tag['href']))
                                    <a href="{{ $tag['href'] }}" class="no-underline"><x-td.home-tag :text="$tag['text']" :icon="$tag['icon']" :tone="$toneMap[$tag['tone']]" /></a>
                                @else
                                    <x-td.home-tag :text="$tag['text']" :icon="$tag['icon']" :tone="$toneMap[$tag['tone']]" />
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>
        </div>

        <p class="hm-foot">
            <span><b>{{ count($children) }}</b> {{ count($children) === 1 ? 'child' : 'children' }}</span>
            <span><b>{{ $waiting_total }}</b> {{ \Illuminate\Support\Str::plural('request', $waiting_total) }} waiting</span>
            @if (count($urgent) > 0)<span><b>{{ count($urgent) }}</b> urgent {{ \Illuminate\Support\Str::plural('report', count($urgent)) }}</span>@endif
        </p>
    @endif
    </div>
</x-layouts::learner>
