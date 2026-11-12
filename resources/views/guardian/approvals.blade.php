{{-- Approvals (D-2102): everything waiting for this guardian, then every recent decision, so nothing decided vanishes. --}}
<x-layouts::learner title="Approvals">
    <x-td.page-head title="Approvals" lede="What your children asked for and what was decided. Every booking, purchase, paid room and live lesson that needs you comes here first." />

    @if (! $is_guardian)
        @include('guardian.partials.not-linked')
    @else
        <div class="grid gap-6">
            <x-td.panel title="Waiting for you" flush>
                @if ($waiting_total === 0)
                    <x-td.state kind="success" title="Nothing is waiting for your approval">New requests appear here and you get an email.</x-td.state>
                @else
                    @foreach ($children as $child)
                        @foreach ($child['pending'] as $approval)
                            @include('guardian._decision', ['approval' => $approval, 'childName' => $child['name']])
                        @endforeach
                    @endforeach
                @endif
            </x-td.panel>

            <x-td.panel title="Recent decisions" flush>
                @if ($decisions === [])
                    <x-td.state kind="empty" title="No decisions yet">Requests you approve or decline stay listed here, with the reason you gave.</x-td.state>
                @else
                    @include('guardian.partials.decisions', ['decisions' => $decisions])
                @endif
            </x-td.panel>
        </div>
    @endif
</x-layouts::learner>
