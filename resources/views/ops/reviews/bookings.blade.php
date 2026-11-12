@php $tz = config('tutor.timezone'); @endphp
<x-layouts::operations title="Bookings">
    <x-td.page-head title="Bookings" lede="Every lesson booking, newest start first, with its state. Open one for its full timeline." />
    @include('ops.reviews._tabs')

    <form method="GET" action="{{ route('ops.reviews.bookings') }}" class="ops-act mb-5">
        <x-td.field label="State" for="b-status"><x-td.select name="status" id="b-status" :options="collect(\App\Enums\SessionStatus::cases())->mapWithKeys(fn ($s) => [$s->value => ucfirst(str_replace('_', ' ', $s->value === 'pending' ? 'requested' : $s->value))])->all()" :value="$status" placeholder="Any" /></x-td.field>
        <x-td.button type="submit" icon="funnel">Filter</x-td.button>
    </form>

    <x-td.panel flush>
        @if ($bookings->isEmpty())
            <x-td.state kind="empty" title="No bookings{{ $status ? ' in this state' : ' yet' }}" />
        @else
            <x-td.table caption="Bookings" caption-hidden stack>
                <thead><tr><th scope="col">Starts</th><th scope="col" data-stack="title">Subject</th><th scope="col">Tutor</th><th scope="col">Learner</th><th scope="col">State</th><th scope="col" class="is-num" data-stack="key">Price</th></tr></thead>
                <tbody>
                    @foreach ($bookings as $b)
                        <tr>
                            <td class="ops-num text-sm"><a href="{{ route('ops.reviews.booking', $b) }}">{{ $b->starts_at?->timezone($tz)->format('j M Y H:i') }}</a></td>
                            <td>{{ $b->subject?->name ?? '—' }}</td>
                            <td>{{ $b->tutorProfile?->user?->name ?? '—' }}</td>
                            <td>{{ $b->student?->name ?? ($b->kind === 'group' ? 'Group lesson' : '—') }}</td>
                            <td>{{ ucfirst(str_replace('_', ' ', $b->status === 'pending' ? 'requested' : $b->status)) }}</td>
                            <td class="is-num">{{ $b->price_minor !== null ? \App\Domain\Money\Money::of((int) $b->price_minor, $b->currency ?: 'ZAR')->format() : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-td.table>
            @if ($bookings->hasPages())<div class="px-5 py-3"><x-td.pagination :paginator="$bookings" label="Booking pages" /></div>@endif
        @endif
    </x-td.panel>
</x-layouts::operations>
