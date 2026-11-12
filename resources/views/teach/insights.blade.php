@php
    $m = fn (int $minor) => \App\Domain\Teach\Support\TutorContext::money($minor, $data['currency']);
    $a = $data['answered'];
    $answeredTotal = $a['accepted'] + $a['declined'];
    $acceptRate = $answeredTotal > 0 ? (int) round(100 * $a['accepted'] / $answeredTotal) : null;
    $s = $data['students'];
    $r = $data['reviews'];
    $hasAnything = $data['requests_12w'] > 0 || collect($data['months'])->sum('value') !== 0 || $s['with_completed'] > 0 || $data['courses']['enrolments'] > 0;
    $last = end($data['weeks']);
    $prev = $data['weeks'][count($data['weeks']) - 2] ?? null;
    $finding = $hasAnything
        ? $data['requests_12w'].' lesson '.\Illuminate\Support\Str::plural('request', $data['requests_12w']).' in the last 12 weeks; '.$last['value'].' this week'.($prev ? ' against '.$prev['value'].' the week before' : '').'.'
        : 'Nothing to measure yet. These figures fill in from real bookings, sales and reviews.';
@endphp
<x-layouts::workspace title="Insights">
    <x-td.page-head title="Insights" :lede="$finding" />

    <div class="td-panels td-panels--2">
        <x-td.panel title="Lesson requests per week">
            <x-slot:actions><span class="text-sm text-td-ink-2">Last 12 weeks, weeks start Monday</span></x-slot:actions>
            @if ($data['requests_12w'] === 0)
                <x-td.state kind="empty" title="No requests in 12 weeks">Each one-to-one, package or weekly request a learner sends is counted here, in the week it arrived. Share your storefront link to bring the first ones in.
                    <x-slot:actions><x-td.button href="{{ route('teach.storefront') }}" size="sm">Storefront and links</x-td.button></x-slot:actions>
                </x-td.state>
            @else
                @include('teach.partials.bar-chart', ['items' => $data['weeks'], 'caption' => 'Lesson requests received per week, last 12 weeks', 'id' => 'chart-requests', 'currency' => $data['currency']])
            @endif
        </x-td.panel>
        <x-td.panel title="How you answered">
            <x-slot:actions><span class="text-sm text-td-ink-2">Last 90 days</span></x-slot:actions>
            <x-td.stat-strip label="Answers to requests" :items="[
                ['label' => 'Accepted', 'value' => (string) $a['accepted']],
                ['label' => 'Declined', 'value' => (string) $a['declined']],
                ['label' => 'Accepted share', 'value' => $acceptRate === null ? '—' : $acceptRate.'%', 'hint' => $acceptRate === null ? 'No answered requests yet' : 'Of '.$answeredTotal.' answered'],
            ]" />
            <p class="mb-0 mt-4 text-sm text-td-ink-2">From the booking timeline: each accept and decline you made, including weekly series one lesson at a time.</p>
        </x-td.panel>
    </div>

    <div class="td-panels td-panels--2 mt-6">
        <x-td.panel title="What you kept each month">
            <x-slot:actions><span class="text-sm text-td-ink-2">Net of commission and refunds, last 6 months</span></x-slot:actions>
            @if (collect($data['months'])->sum('value') === 0)
                <x-td.state kind="empty" title="No earnings yet">When learners pay for lessons, courses or materials, what you keep after commission is added up here by month. <a class="td-link" href="{{ route('teach.earnings') }}">Earnings</a> lists every line.</x-td.state>
            @else
                @include('teach.partials.bar-chart', ['items' => $data['months'], 'money' => true, 'currency' => $data['currency'], 'caption' => 'Net earnings per month, last 6 months', 'id' => 'chart-months'])
            @endif
        </x-td.panel>
        <x-td.panel title="Students, courses and reviews">
            <x-td.stat-strip label="Students and courses" :items="[
                ['label' => 'Returning students', 'value' => $s['with_completed'] === 0 ? '—' : $s['returning'].' of '.$s['with_completed'], 'hint' => $s['with_completed'] === 0 ? 'No completed lessons yet' : 'Two or more completed lessons'],
                ['label' => 'Course enrolments', 'value' => (string) $data['courses']['enrolments'], 'hint' => $data['courses']['paid_sales'].' paid, '.strip_tags((string) $m($data['courses']['net_minor'])).' kept'],
            ]" />
            <h3 class="mb-2 mt-5 text-sm font-bold">Reviews</h3>
            @if ($r['count'] === 0)
                <p class="m-0 text-td-ink-2">No published reviews yet. Learners can review a lesson once it is completed.</p>
            @else
                <p class="mt-0">{{ $r['count'] }} published, average <strong>{{ number_format($r['average'], 1) }}</strong> of 5.</p>
                <x-td.table caption="Reviews by stars" caption-hidden dense>
                    <thead><tr><th scope="col">Stars</th><th scope="col" class="is-num">Reviews</th></tr></thead>
                    <tbody>@foreach ($r['distribution'] as $stars => $n)<tr><td>{{ $stars }}</td><td class="is-num">{{ $n }}</td></tr>@endforeach</tbody>
                </x-td.table>
            @endif
        </x-td.panel>
    </div>

    <x-td.panel title="Where learners come from" class="mt-6">
        <x-td.stat-strip label="Referral link" :items="[
            ['label' => 'Signed-in visitors', 'value' => (string) $data['referral']['visitors'], 'hint' => 'Through your referral link, last 90 days'],
            ['label' => 'Of them, asked for a lesson', 'value' => (string) $data['referral']['attributed'], 'hint' => 'Within 30 days of their first visit'],
        ]" />
        <p class="mb-0 mt-4 text-sm text-td-ink-2">Dot.Tutor does not count views of your storefront or profile, so there is no view-to-booking rate. Only visits through your <a class="td-link" href="{{ route('teach.storefront') }}">referral link</a> are counted.</p>
    </x-td.panel>
</x-layouts::workspace>
