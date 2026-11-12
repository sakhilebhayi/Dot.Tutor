{{-- The guardian's safety page (GET /safety/guardian, D-2101): one row per report, safeguarding case or account
     notice about the guardian's own children: what, when, where it stands, what happens next, what they can do. --}}
@php($criticalHours = (int) config('safety.reports.response_hours.critical', 1))
<x-layouts::learner title="Safety">
    <x-td.page-head title="Safety" lede="Reports, safeguarding cases and account notices about your children, and where each one stands.">
        @if ($is_guardian && \Illuminate\Support\Facades\Route::has('safety.conversations.index'))
            <x-slot:actions><x-td.button :href="route('safety.conversations.index').'#support'" icon="life-buoy">Write to Dot.Tutor support</x-td.button></x-slot:actions>
        @endif
    </x-td.page-head>

    @if (! $is_guardian)
        @include('guardian.partials.not-linked')
    @else
        @php($rows = collect(app(\App\Domain\Ops\Guardian\GuardianSafety::class)->sorted(collect($children)->flatMap(fn ($c) => $c['safety'])->all())))
        <div class="grid gap-6">
            <x-td.notice tone="warning" title="If a child is in danger now, do not wait for Dot.Tutor">
                <p class="gd-help__numbers"><span>Police: <a href="tel:10111">10111</a></span><span>Childline: <a href="tel:116">116</a> (free, day and night)</span></p>
            </x-td.notice>

            <x-td.panel title="Reports and notices" flush>
                @if ($rows->isEmpty())
                    <x-td.state kind="success" title="No reports about your children">If {{ count($children) === 1 ? $children[0]['name'] : 'one of your children' }} reports something, or something is reported in their conversations, it appears here with what happens next.</x-td.state>
                @else
                    <ul class="gd-rows">
                        @foreach ($rows as $row)
                            @include('guardian.partials.safety-row', ['row' => $row])
                        @endforeach
                    </ul>
                @endif
            </x-td.panel>

            <x-td.panel title="How reports are handled">
                <p class="mt-0">A report about contact with a learner under 18 goes first: a safeguarding officer must take it on within {{ $criticalHours === 1 ? 'an hour' : $criticalHours.' hours' }}, and it is raised to senior staff if nobody does.</p>
                <p class="mb-0 ops-muted text-sm">You see what was reported, when, and where it stands. What other people wrote and the officers' notes stay with the officers. To report something new, use Report on the message in a conversation, or write to support.</p>
            </x-td.panel>
        </div>
    @endif
</x-layouts::learner>
