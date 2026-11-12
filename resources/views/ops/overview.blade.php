{{-- Command centre overview (brief section 20). The screen's question: what needs a person now, and is learning
     happening? Every figure is counted from the platform's own records on this request (App\Domain\Ops\Insights). --}}
@php
    $d = $data;
    $waiting = collect($queues)->filter(fn ($q) => $q['count'] > 0);
    $dash = fn ($v, $suffix = '') => $v === null ? '—' : $v.$suffix;
@endphp
<x-layouts::operations title="Command centre">
    <x-td.page-head title="Command centre" lede="What is waiting for you first, then the last {{ $d['window_days'] }} days counted from Dot.Tutor's own records. Nothing here is estimated." />

    <div class="grid gap-6">
        <x-td.panel title="Waiting for you" flush>
            @if ($waiting->isEmpty())
                <x-td.state kind="success" title="Nothing is waiting in your queues">
                    {{ count($queues) === 0 ? 'Your role has no work queues on this screen.' : 'Every queue your role covers is empty right now.' }}
                </x-td.state>
            @else
                <ul class="ops-work">
                    @foreach ($waiting as $q)
                        <li>
                            <div>
                                <p class="ops-work__title"><a href="{{ route($q['route']) }}">{{ $q['title'] }}</a></p>
                                @if ($q['urgent'] > 0)
                                    <p class="ops-work__meta">@include('ops.partials.tag', ['text' => $q['urgent'].' '.$q['urgent_label'], 'tone' => $q['tone'] === 'neutral' ? 'warning' : $q['tone']])</p>
                                @endif
                            </div>
                            <span class="ops-work__count">{{ $q['count'] }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-td.panel>

        <x-td.stat-strip label="People, last {{ $d['window_days'] }} days" :items="[
            ['label' => 'Active learners', 'value' => $d['people']['learners_active'], 'hint' => 'with a lesson or a course enrolment'],
            ['label' => 'Active tutors', 'value' => $d['people']['tutors_active'], 'hint' => 'of '.$d['people']['tutors_approved'].' approved; '.$d['people']['tutors_pending'].' applications waiting'],
            ['label' => 'Lessons held', 'value' => $d['lessons']['completed'], 'hint' => $d['lessons']['completion_rate'] === null ? 'no lessons reached their start time yet' : $d['lessons']['completion_rate'].'% of lessons held or missed took place'],
            ['label' => 'Guardians linked', 'value' => $d['people']['guardians_linked'], 'hint' => 'with consent recorded'],
        ]" />

        <div class="td-panels td-panels--2">
            <x-td.panel title="Lessons requested each week">
                @if (collect($d['weekly'])->sum('value') === 0)
                    <x-td.state kind="empty" title="No lessons requested in the last 12 weeks">Requests appear here as learners book tutors.</x-td.state>
                @else
                    @include('ops.partials.columns', ['points' => $d['weekly'], 'caption' => 'Lessons requested per week, last 12 weeks', 'unit' => 'requests'])
                @endif
            </x-td.panel>
            <x-td.panel title="Lessons, last {{ $d['window_days'] }} days">
                <dl class="ops-facts">
                    <dt>Requested</dt><dd class="ops-num">{{ $d['lessons']['requested'] }}</dd>
                    <dt>Held</dt><dd class="ops-num">{{ $d['lessons']['completed'] }}</dd>
                    <dt>Learner did not come</dt><dd class="ops-num">{{ $d['lessons']['no_show'] }}</dd>
                    <dt>Cancelled</dt><dd class="ops-num">{{ $d['lessons']['cancelled'] }}</dd>
                    <dt>Disputed now</dt><dd class="ops-num">{{ $d['lessons']['disputed'] }}</dd>
                </dl>
            </x-td.panel>
        </div>

        @if ($d['money'] !== null)
            @php($m = $d['money'])
            <x-td.stat-strip label="Money, last {{ $d['window_days'] }} days" :items="[
                ['label' => 'Paid by learners', 'value' => \App\Domain\Money\Money::of($m['cash_minor'], $m['currency'])->format(), 'hint' => $m['paid_orders'].' paid orders'],
                ['label' => 'Platform commission', 'value' => \App\Domain\Money\Money::of($m['commission_minor'], $m['currency'])->format(), 'hint' => 'from the ledger'],
                ['label' => 'Tutor earnings', 'value' => \App\Domain\Money\Money::of($m['tutor_earnings_minor'], $m['currency'])->format(), 'hint' => 'net of commission'],
                ['label' => 'Course sales', 'value' => $m['course_sales'], 'hint' => 'and '.$m['resource_sales'].' resource sales'],
                ['label' => 'Refunded', 'value' => \App\Domain\Money\Money::of($m['refunds_minor'], $m['currency'])->format(), 'hint' => 'processed by Paystack'],
            ]" />
        @endif

        <div class="td-panels">
            <x-td.panel title="Teaching languages of approved tutors">
                @include('ops.partials.bars', ['rows' => $d['languages']['teaching']->map(fn ($l) => ['label' => $l->native_name && $l->native_name !== $l->name ? $l->native_name.' ('.$l->name.')' : $l->name, 'value' => (int) $l->n, 'lang' => $l->code])->all(), 'label' => 'Tutors per teaching language', 'empty' => 'No approved tutor has listed a teaching language yet.'])
            </x-td.panel>
            <x-td.panel title="Languages learners want to be taught in">
                @include('ops.partials.bars', ['rows' => $d['languages']['preferred']->map(fn ($l) => ['label' => $l->native_name && $l->native_name !== $l->name ? $l->native_name.' ('.$l->name.')' : $l->name, 'value' => (int) $l->n, 'lang' => $l->code])->all(), 'label' => 'Learners per preferred teaching language', 'empty' => 'No learner has set a preferred teaching language yet.'])
            </x-td.panel>
            <x-td.panel title="Where learners study">
                @if ($d['geography']['profiles'] === 0)
                    <p class="m-0 ops-muted">No learner has completed a learning profile yet, so country and curriculum are not known.</p>
                @else
                    <div class="grid gap-5">
                        @include('ops.partials.bars', ['rows' => $d['geography']['countries']->map(fn ($c) => ['label' => $c->name, 'value' => (int) $c->n])->all(), 'label' => 'Learners by country'])
                        @include('ops.partials.bars', ['rows' => $d['geography']['curricula']->map(fn ($c) => ['label' => $c->name, 'value' => (int) $c->n])->all(), 'label' => 'Learners by curriculum'])
                    </div>
                @endif
            </x-td.panel>
        </div>

        <div class="td-panels td-panels--2">
            <x-td.panel title="Learners who came back">
                @php($hasCohorts = collect($d['cohorts'])->sum('size') > 0)
                @if (! $hasCohorts)
                    <x-td.state kind="empty" title="No learner has had a lesson in the last six months">Each row will show learners by the month of their first lesson and how many had another lesson one, two and three months later.</x-td.state>
                @else
                    <x-td.table caption="Share of each month's new learners with a lesson in a later month" class="ops-heat" dense>
                        <thead><tr><th scope="col">First lesson</th><th scope="col" class="is-num">Learners</th><th scope="col">1 month on</th><th scope="col">2 months on</th><th scope="col">3 months on</th></tr></thead>
                        <tbody>
                            @foreach ($d['cohorts'] as $row)
                                <tr>
                                    <th scope="row">{{ $row['month'] }}</th>
                                    <td class="is-num">{{ $row['size'] }}</td>
                                    @foreach ($row['cells'] as $cell)
                                        @if ($cell === null)
                                            <td class="ops-heat__cell ops-heat__cell--none">{{ $row['size'] === 0 ? '—' : 'not yet' }}</td>
                                        @else
                                            <td class="ops-heat__cell" data-step="{{ $cell <= 0 ? 0 : min(4, (int) ceil($cell / 25)) }}">{{ $cell }}%</td>
                                        @endif
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </x-td.table>
                @endif
            </x-td.panel>
            <x-td.panel title="How learners rate lessons">
                @php($s = $d['satisfaction'])
                @if ($s['lessons']['total'] === 0 && $s['courses']['total'] === 0)
                    <p class="m-0 ops-muted">No published reviews yet. Reviews come only from learners who took the lesson or the course.</p>
                @else
                    <div class="grid gap-5">
                        <div>
                            <p class="m-0 mb-3"><span class="ops-work__count">{{ $dash($s['lessons']['average']) }}</span> <span class="ops-muted">average of {{ $s['lessons']['total'] }} published lesson reviews</span></p>
                            @include('ops.partials.bars', ['rows' => collect($s['lessons']['distribution'])->map(fn ($r) => ['label' => $r['stars'].' of 5', 'value' => $r['n']])->all(), 'label' => 'Lesson reviews by rating'])
                        </div>
                        <p class="m-0 ops-muted">Courses: {{ $s['courses']['total'] === 0 ? 'no published reviews yet' : $s['courses']['average'].' average of '.$s['courses']['total'].' reviews' }}.</p>
                    </div>
                @endif
            </x-td.panel>
        </div>

        <x-td.stat-strip label="Learning, last {{ $d['window_days'] }} days" :items="[
            ['label' => 'Course enrolments', 'value' => $d['learning']['enrolments'], 'hint' => $d['learning']['courses_published'].' courses published'],
            ['label' => 'Resources added', 'value' => $d['learning']['resources_added'], 'hint' => $d['learning']['resources_published'].' published in the library; downloads are not counted'],
            ['label' => 'Resource recommendations', 'value' => $d['learning']['recommendations'], 'hint' => 'by learners and tutors'],
            ['label' => 'Practice attempts', 'value' => $d['learning']['attempts'], 'hint' => 'submitted quizzes and assessments'],
            ['label' => 'Room questions', 'value' => $d['learning']['room_questions'], 'hint' => $d['learning']['room_answers'].' answers posted'],
        ]" />
    </div>
</x-layouts::operations>
