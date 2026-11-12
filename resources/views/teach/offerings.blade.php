@php
    $money = fn (int $minor, ?string $c = null) => \App\Domain\Teach\Support\TutorContext::money($minor, $c);
    $formatLabel = ['online' => 'Online', 'in_person' => 'In person', 'either' => 'Online or in person'];
@endphp
<x-layouts::workspace title="Offerings and pricing" step="dense">
    <x-td.page-head title="Offerings and pricing" :lede="$active->isEmpty() ? 'An offering is what learners book. Create one and see what you keep before you save it.' : $active->count().' bookable '.\Illuminate\Support\Str::plural('offering', $active->count()).'. What you keep uses today\'s commission rate; a sale keeps the rate it was made at.'">
        <x-slot:actions>
            <x-td.button href="{{ route('teach.offerings.create') }}" variant="primary" icon="plus">New offering</x-td.button>
        </x-slot:actions>
    </x-td.page-head>

    <x-td.panel title="Bookable now" flush>
        @if ($active->isEmpty())
            <div class="p-5"><x-td.state kind="empty" title="No offerings yet">Start with a one-to-one lesson. Packages, group lessons and weekly lessons help learners commit and give you steadier income.
                <x-slot:actions><x-td.button href="{{ route('teach.offerings.create', ['type' => 'one_to_one']) }}" variant="primary" size="sm">Create a one-to-one lesson</x-td.button></x-slot:actions>
            </x-td.state></div>
        @else
            <x-td.table caption="Bookable offerings" caption-hidden dense stack>
                <thead><tr>
                    <th scope="col">Offering</th><th scope="col">Type</th><th scope="col">Length, where</th>
                    <th scope="col" class="is-num">Price</th><th scope="col" class="is-num">Commission</th><th scope="col" class="is-num" data-stack="key">You keep</th>
                    <th scope="col" class="is-num">Delivered</th><th scope="col" data-stack="action"><span class="td-sr">Actions</span></th>
                </tr></thead>
                <tbody>
                    @foreach ($active as $o)
                        @php $split = $net($o); @endphp
                        <tr>
                            <th scope="row">{{ $o->title }}<span class="block text-sm font-normal text-td-ink-2">{{ $o->subjects->pluck('name')->implode(', ') }}</span></th>
                            <td>{{ $o->typeEnum()->label() }}
                                @if ($o->type === 'group')<span class="block text-sm text-td-ink-2">{{ $o->capacity }} seats</span>@endif
                                @if ($o->type === 'package')<span class="block text-sm text-td-ink-2">{{ $o->package_lessons }} lessons, {{ $o->package_valid_days }} days</span>@endif
                                @if ($o->type === 'recurring_weekly')<span class="block text-sm text-td-ink-2">{{ $o->recurring_weeks }} weeks</span>@endif
                            </td>
                            <td>{{ $o->duration_minutes }} min, {{ $formatLabel[$o->format] ?? $o->format }}</td>
                            <td class="is-num">{{ $money($o->price_minor, $o->currency) }}</td>
                            <td class="is-num">{{ $money($split['commission']->minor, $o->currency) }}</td>
                            <td class="is-num font-bold">{{ $money($split['net']->minor, $o->currency) }}</td>
                            <td class="is-num">{{ (int) ($delivered[$o->id] ?? 0) }}</td>
                            <td>
                                <div class="flex flex-wrap justify-end gap-2">
                                    <x-td.button href="{{ route('teach.offerings.edit', $o) }}" size="sm" icon="pencil">Edit<span class="td-sr"> {{ $o->title }}</span></x-td.button>
                                    <form method="POST" action="{{ route('teach.offerings.archive', $o) }}">@csrf<x-td.button type="submit" variant="quiet" size="sm">Archive<span class="td-sr"> {{ $o->title }}</span></x-td.button></form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </x-td.table>
        @endif
    </x-td.panel>

    <div class="td-panels td-panels--2 mt-6">
        <x-td.panel title="Discount codes">
            <p class="mt-0 text-sm text-td-ink-2">Codes you fund work only on your own offerings, courses and materials. The discount comes off your price, so commission is taken from the lower amount.</p>
            @if ($coupons->isEmpty())
                <p class="text-td-ink-2">No codes yet.</p>
            @else
                <x-td.table caption="Your discount codes" caption-hidden dense stack>
                    <thead><tr><th scope="col">Code</th><th scope="col">Discount</th><th scope="col" class="is-num">Used</th><th scope="col">Limits</th><th scope="col">State</th><th scope="col" data-stack="action"><span class="td-sr">Actions</span></th></tr></thead>
                    <tbody>
                        @foreach ($coupons as $c)
                            @php $api = $c->toApi(); @endphp
                            <tr>
                                <th scope="row">{{ $c->code }}</th>
                                <td>{{ $c->kind === 'percent' ? rtrim(rtrim(number_format($api['percent'], 2), '0'), '.').'% off' : 'Less '.$money((int) $c->amount_minor, $c->currency) }}</td>
                                <td class="is-num">{{ $api['used'] }}{{ $c->max_redemptions ? ' of '.$c->max_redemptions : '' }}</td>
                                <td class="text-sm">{{ $c->per_user_limit }} per learner{{ $c->min_order_minor ? ', from '.$money((int) $c->min_order_minor, $c->currency) : '' }}{{ $c->expires_at ? ', until '.$c->expires_at->format('j M Y') : '' }}</td>
                                <td>{{ $c->is_active && (! $c->expires_at || $c->expires_at->isFuture()) ? 'Live' : 'Off' }}</td>
                                <td>@if ($c->is_active)<form method="POST" action="{{ route('teach.offerings.coupons.deactivate', $c) }}">@csrf<x-td.button type="submit" variant="quiet" size="sm">Switch off<span class="td-sr"> {{ $c->code }}</span></x-td.button></form>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-td.table>
            @endif
        </x-td.panel>
        <x-td.panel title="New discount code">
            @if (! $approved)
                <x-td.state kind="empty" title="Opens after approval">You can create discount codes once an operator approves your profile.</x-td.state>
            @else
                <form method="POST" action="{{ route('teach.offerings.coupons.store') }}" class="grid gap-4">
                    @csrf
                    <x-td.field label="Code" for="code" hint="Letters, digits, dash. Learners type it at checkout."><x-td.input name="code" minlength="4" maxlength="40" required pattern="[A-Za-z0-9_-]+" :hint="true" /></x-td.field>
                    <fieldset class="grid gap-2">
                        <legend class="td-label">Discount</legend>
                        <x-td.check type="radio" name="kind" value="percent" :checked="true">A percentage off</x-td.check>
                        <x-td.check type="radio" name="kind" value="fixed">An amount off</x-td.check>
                    </fieldset>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-td.field label="Percent" for="percent" optional><x-td.input name="percent" inputmode="decimal" /></x-td.field>
                        <x-td.field label="Amount (ZAR)" for="amount" optional><x-td.input name="amount" inputmode="decimal" /></x-td.field>
                        <x-td.field label="Total uses" for="max_redemptions" optional><x-td.input name="max_redemptions" type="number" min="1" /></x-td.field>
                        <x-td.field label="Uses per learner" for="per_user_limit"><x-td.input name="per_user_limit" type="number" min="1" max="100" value="1" /></x-td.field>
                        <x-td.field label="Minimum order (ZAR)" for="min_order" optional><x-td.input name="min_order" inputmode="decimal" /></x-td.field>
                        <x-td.field label="Ends on" for="expires_at" optional><x-td.input name="expires_at" type="date" min="{{ now()->addDay()->format('Y-m-d') }}" /></x-td.field>
                    </div>
                    <div><x-td.button type="submit" variant="primary">Create code</x-td.button></div>
                </form>
            @endif
        </x-td.panel>
    </div>

    @if ($archived->isNotEmpty())
        <x-td.panel title="Archived" class="mt-6">
            <ul class="m-0 grid list-none gap-2 p-0">
                @foreach ($archived as $o)
                    <li class="flex flex-wrap items-center justify-between gap-3">
                        <span>{{ $o->title }} <span class="text-td-ink-2">{{ $o->typeEnum()->label() }}, {{ $money($o->price_minor, $o->currency) }}</span></span>
                        <form method="POST" action="{{ route('teach.offerings.restore', $o) }}">@csrf<x-td.button type="submit" size="sm">Make bookable again<span class="td-sr"> {{ $o->title }}</span></x-td.button></form>
                    </li>
                @endforeach
            </ul>
        </x-td.panel>
    @endif
</x-layouts::workspace>
