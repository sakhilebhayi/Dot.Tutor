@php $tz = config('tutor.timezone'); $b = $booking; @endphp
<x-layouts::operations title="Booking {{ $b->id }}">
    <x-td.page-head :title="($b->subject?->name ?? 'Lesson').' · '.$b->starts_at?->timezone($tz)->format('j M Y H:i')" lede="Booking {{ $b->id }}. The timeline is append-only.">
        <x-slot:actions><x-td.button :href="route('ops.reviews.bookings')" icon="arrow-left" variant="quiet">All bookings</x-td.button></x-slot:actions>
    </x-td.page-head>

    <div class="grid gap-6">
        <div class="td-panels td-panels--2">
            <x-td.panel title="Booking">
                <dl class="ops-facts">
                    <dt>State</dt><dd>{{ ucfirst(str_replace('_', ' ', $b->status === 'pending' ? 'requested' : $b->status)) }}</dd>
                    <dt>Tutor</dt><dd>{{ $b->tutorProfile?->user?->name ?? '—' }}</dd>
                    <dt>Learner</dt><dd>{{ $b->student?->name ?? ($b->kind === 'group' ? 'Group lesson, '.$b->seats_taken.' of '.$b->capacity.' seats' : '—') }}</dd>
                    <dt>Length</dt><dd>{{ $b->duration_minutes }} minutes, {{ $b->delivery }}</dd>
                    <dt>Price</dt><dd>{{ $b->price_minor !== null ? \App\Domain\Money\Money::of((int) $b->price_minor, $b->currency ?: 'ZAR')->format() : 'Not priced' }} · payment {{ str_replace('_', ' ', $b->payment_status ?? 'not needed') }}</dd>
                    <dt>Guardian</dt><dd>{{ $b->guardian_status ? str_replace('_', ' ', $b->guardian_status) : 'Not needed' }}</dd>
                    @if ($canFinance)
                        <dt>Orders</dt><dd>@forelse ($orders as $o)<a href="{{ route('ops.payments.order', $o->uuid) }}">{{ \Illuminate\Support\Str::limit($o->uuid, 8, '') }}</a> ({{ $o->status }}){{ $loop->last ? '' : ', ' }}@empty None @endforelse</dd>
                    @endif
                </dl>
            </x-td.panel>
            <x-td.panel title="Review">
                @forelse ($review as $r)
                    <p class="m-0 mb-2">{{ $r->rating }} of 5 · {{ $r->status }}</p>
                    @if ($r->review)<p class="m-0 whitespace-pre-line">{{ $r->review }}</p>@endif
                @empty
                    <p class="m-0 ops-muted">No review for this lesson.</p>
                @endforelse
            </x-td.panel>
        </div>

        @if ($b->status === 'disputed' && $canResolve)
            <x-td.panel title="Resolve the dispute">@include('ops.reviews._resolve', ['booking' => $b])</x-td.panel>
        @endif

        <x-td.panel title="Timeline" flush>
            @if ($events->isEmpty())
                <x-td.state kind="empty" title="No events recorded">Lessons booked before R-47 have no timeline.</x-td.state>
            @else
                <x-td.table caption="Booking timeline" caption-hidden dense stack>
                    <thead><tr><th scope="col">When</th><th scope="col" data-stack="title">Event</th><th scope="col">Change</th><th scope="col">By</th><th scope="col">Reason</th></tr></thead>
                    <tbody>
                        @foreach ($events as $e)
                            <tr>
                                <td class="ops-num text-sm" style="white-space:nowrap">{{ \Illuminate\Support\Carbon::parse($e->created_at)->timezone($tz)->format('j M H:i') }}</td>
                                <td>{{ str_replace('_', ' ', $e->event) }}</td>
                                <td class="text-sm">{{ $e->from_state ? str_replace('_', ' ', $e->from_state).' to ' : '' }}{{ str_replace('_', ' ', (string) $e->to_state) }}</td>
                                <td class="text-sm">{{ $e->actor_id ? ($actors[$e->actor_id] ?? 'User '.$e->actor_id) : 'System' }}</td>
                                <td class="text-sm">{{ $e->reason }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-td.table>
            @endif
        </x-td.panel>
    </div>
</x-layouts::operations>
