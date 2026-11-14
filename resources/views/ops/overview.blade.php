{{-- Command centre overview (brief section 20). The screen's question: what needs a person now, and is learning
     happening? Every figure is counted from the platform's own records on this request (App\Domain\Ops\Insights).
     No query is added here (R-51): the page lays out what Queues and Overview already return. --}}
@php
    use Illuminate\Support\Str;
    $d = $data;
    $waiting = collect($queues)->filter(fn ($q) => $q['count'] > 0);
    $dash = fn ($v, $suffix = '') => $v === null ? '—' : $v.$suffix;
    $window = $d['window_days'];
    // The head's one primary action (R-50): a child-safety report before anything, then a queue that is past its time,
    // then the first with work in it, else the first queue this role covers. Counts are the list's own.
    $top = $waiting->firstWhere('key', 'child_safety') ?? $waiting->first(fn ($q) => $q['urgent'] > 0) ?? $waiting->first() ?? ($queues[0] ?? null);
    if ($top !== null) {
        $primary = ['label' => 'Open '.Str::lcfirst($top['title']), 'href' => route($top['route'])];
    } else {
        $primary = ['label' => 'Open your account', 'href' => route('profile.show'), 'icon' => 'user'];
    }
    $otherItems = $waiting->sum('count') - ($top['count'] ?? 0);
    [$headline, $sub] = match (true) {
        $top === null => ['Your role has no work queues on this screen.', 'The figures below are counted from Dot.Tutor\'s own records, last '.$window.' days.'],
        $top['count'] === 0 => ['Every queue your role covers is empty.', 'The figures below are counted from Dot.Tutor\'s own records, last '.$window.' days. Nothing is estimated.'],
        default => [
            $top['title'].': '.(Str::endsWith($top['title'], 'waiting') ? $top['count'] : $top['count'].' waiting').($top['key'] === 'child_safety' ? ', with an hour to respond.' : '.'),
            ($otherItems > 0 ? $otherItems.' more '.Str::plural('item', $otherItems).' wait across '.($waiting->count() - 1).' other '.Str::plural('queue', $waiting->count() - 1).'. ' : '').'The figures below are counted from Dot.Tutor\'s own records, last '.$window.' days.'.($top['urgent'] > 0 ? ' '.$top['urgent'].' '.$top['urgent_label'].'.' : ''),
        ],
    };
    $langName = fn ($l) => $l->native_name && $l->native_name !== $l->name ? $l->native_name.' ('.$l->name.')' : $l->name;
    $hasCohorts = collect($d['cohorts'])->sum('size') > 0;
    $s = $d['satisfaction'];
    $m = $d['money'];
@endphp
<x-layouts::operations title="Command centre">
    <div class="hm">
    <x-td.home-head :headline="$headline" :sub="$sub" :primary="$primary" />

    <div class="hm-split">
        <section aria-labelledby="queues-title">
            <div class="hm-sech"><h2 id="queues-title">Waiting for a person</h2>@if ($waiting->isNotEmpty())<span class="hm-sech__n">{{ $waiting->sum('count') }} {{ Str::plural('item', $waiting->sum('count')) }}, {{ $waiting->count() }} {{ Str::plural('queue', $waiting->count()) }}</span>@endif</div>
            @if ($waiting->isEmpty())
                <div class="hm-empty">
                    <x-td.shelf />
                    <p>{{ count($queues) === 0 ? 'Your role has no work queues on this screen.' : 'Nothing is waiting in your queues. Every queue your role covers is empty right now.' }}</p>
                </div>
            @else
                <ul class="hm-rows">
                    @foreach ($waiting as $q)
                        <li class="hm-row hm-row--dense hm-row--queue">
                            <div><a class="hm-row__link" href="{{ route($q['route']) }}">{{ $q['title'] }}</a></div>
                            <span class="hm-tagcell">
                                @if ($q['urgent'] > 0)
                                    <x-td.home-tag :text="$q['urgent'].' '.$q['urgent_label']" :icon="$q['tone'] === 'danger' ? 'circle-alert' : 'triangle-alert'" :tone="$q['tone'] === 'danger' ? 'danger' : 'warn'" />
                                @elseif ($q['key'] === 'child_safety')
                                    <x-td.home-tag text="Respond within an hour" icon="circle-alert" tone="danger" />
                                @endif
                            </span>
                            <span class="hm-num">{{ $q['count'] }}</span>
                        </li>
                    @endforeach
                </ul>
                <p class="hm-note">Queues with nothing in them are not listed.</p>
            @endif

            <div class="hm-sech hm-gap"><h2>Lessons requested each week</h2></div>
            @if (collect($d['weekly'])->sum('value') === 0)
                <div class="hm-empty"><x-td.shelf /><p>No lessons requested in the last 12 weeks. Requests appear here as learners book tutors.</p></div>
            @else
                @include('ops.partials.columns', ['points' => $d['weekly'], 'caption' => 'Lessons requested per week, last 12 weeks', 'unit' => 'requests'])
            @endif
            <div class="hm-sech hm-gap"><h2 id="study-title">Where learners study</h2></div>
            @if ($d['geography']['profiles'] === 0)
                <p class="hm-note" style="margin-top:0">No learner has completed a learning profile yet, so country and curriculum are not known.</p>
            @else
                <h3 class="hm-sech__n" id="geo-country">Country</h3>
                @include('ops.partials.bars', ['rows' => $d['geography']['countries']->map(fn ($c) => ['label' => $c->name, 'value' => (int) $c->n])->all(), 'label' => 'Learners by country'])
                <h3 class="hm-sech__n hm-gap" id="geo-curriculum">Curriculum</h3>
                @include('ops.partials.bars', ['rows' => $d['geography']['curricula']->map(fn ($c) => ['label' => $c->name, 'value' => (int) $c->n])->all(), 'label' => 'Learners by curriculum'])
            @endif

            <div class="hm-sech hm-gap"><h2>Learners who came back</h2></div>
            @if (! $hasCohorts)
                <p class="hm-note" style="margin-top:0">No learner has had a lesson in the last six months. Each row will show learners by the month of their first lesson and how many had another lesson one, two and three months later.</p>
            @else
                <x-td.table caption="Share of each month's new learners with a lesson in a later month" class="ops-heat" dense stack>
                    <thead><tr><th scope="col">First lesson</th><th scope="col" class="is-num" data-stack="key">Learners</th><th scope="col">1 month on</th><th scope="col">2 months on</th><th scope="col">3 months on</th></tr></thead>
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
        </section>

        <section aria-labelledby="window-title">
            <div class="hm-sech"><h2 id="window-title">Last {{ $window }} days</h2></div>
            <dl class="hm-facts">
                <dt>Active learners</dt><dd><span data-count="{{ $d['people']['learners_active'] }}">{{ $d['people']['learners_active'] }}</span> <small>with a lesson or a course enrolment</small></dd>
                <dt>Active tutors</dt><dd><span data-count="{{ $d['people']['tutors_active'] }}">{{ $d['people']['tutors_active'] }}</span> <small>of {{ $d['people']['tutors_approved'] }} approved; {{ $d['people']['tutors_pending'] }} {{ Str::plural('application', $d['people']['tutors_pending']) }} waiting</small></dd>
                <dt>Guardians linked</dt><dd><span data-count="{{ $d['people']['guardians_linked'] }}">{{ $d['people']['guardians_linked'] }}</span> <small>with consent recorded</small></dd>
                <dt>Lessons requested</dt><dd><span data-count="{{ $d['lessons']['requested'] }}">{{ $d['lessons']['requested'] }}</span></dd>
                <dt>Lessons held</dt><dd><span data-count="{{ $d['lessons']['completed'] }}">{{ $d['lessons']['completed'] }}</span> <small>{{ $d['lessons']['completion_rate'] === null ? 'no lesson has reached its start time yet' : $d['lessons']['completion_rate'].'% of started lessons held' }}</small></dd>
                <dt>Learner did not come</dt><dd>{{ $d['lessons']['no_show'] }}</dd>
                <dt>Cancelled</dt><dd>{{ $d['lessons']['cancelled'] }}</dd>
                <dt>Disputed now</dt><dd>{{ $d['lessons']['disputed'] }}</dd>
            </dl>

            @if ($m !== null)
                <div class="hm-sech hm-gap"><h2>Money</h2></div>
                <dl class="hm-facts">
                    <dt>Paid by learners</dt><dd>{{ \App\Domain\Money\Money::of($m['cash_minor'], $m['currency'])->format() }} <small>{{ $m['paid_orders'] }} paid {{ Str::plural('order', $m['paid_orders']) }}</small></dd>
                    <dt>Platform commission</dt><dd>{{ \App\Domain\Money\Money::of($m['commission_minor'], $m['currency'])->format() }} <small>from the ledger</small></dd>
                    <dt>Tutor earnings</dt><dd>{{ \App\Domain\Money\Money::of($m['tutor_earnings_minor'], $m['currency'])->format() }} <small>net of commission</small></dd>
                    <dt>Course sales</dt><dd>{{ $m['course_sales'] }} <small>and {{ $m['resource_sales'] }} resource sales</small></dd>
                    <dt>Refunded</dt><dd>{{ \App\Domain\Money\Money::of($m['refunds_minor'], $m['currency'])->format() }} <small>processed by Paystack</small></dd>
                </dl>
            @endif

            <div class="hm-sech hm-gap"><h2>Teaching languages</h2><span class="hm-sech__n">approved tutors</span></div>
            @if ($d['languages']['teaching']->isEmpty())
                <p class="hm-note" style="margin-top:0">No approved tutor has listed a teaching language yet.</p>
            @else
                <dl class="hm-facts">
                    @foreach ($d['languages']['teaching'] as $l)
                        <dt lang="{{ $l->code }}">{{ $langName($l) }}</dt><dd>{{ (int) $l->n }}</dd>
                    @endforeach
                </dl>
            @endif

            <div class="hm-sech hm-gap"><h2>Languages learners want</h2><span class="hm-sech__n">to be taught in</span></div>
            @if ($d['languages']['preferred']->isEmpty())
                <p class="hm-note" style="margin-top:0">No learner has set a preferred teaching language yet.</p>
            @else
                <dl class="hm-facts">
                    @foreach ($d['languages']['preferred'] as $l)
                        <dt lang="{{ $l->code }}">{{ $langName($l) }}</dt><dd>{{ (int) $l->n }}</dd>
                    @endforeach
                </dl>
            @endif
            <div class="hm-sech hm-gap"><h2 id="learning-title">Learning, last {{ $window }} days</h2></div>
            <dl class="hm-facts">
                <dt>Course enrolments</dt><dd>{{ $d['learning']['enrolments'] }} <small>{{ $d['learning']['courses_published'] }} courses published</small></dd>
                <dt>Resources added</dt><dd>{{ $d['learning']['resources_added'] }} <small>{{ $d['learning']['resources_published'] }} published in the library; downloads are not counted</small></dd>
                <dt>Recommendations</dt><dd>{{ $d['learning']['recommendations'] }} <small>by learners and tutors</small></dd>
                <dt>Practice attempts</dt><dd>{{ $d['learning']['attempts'] }} <small>submitted quizzes and assessments</small></dd>
                <dt>Room questions</dt><dd>{{ $d['learning']['room_questions'] }} <small>{{ $d['learning']['room_answers'] }} {{ Str::plural('answer', $d['learning']['room_answers']) }} posted</small></dd>
            </dl>

            <div class="hm-sech hm-gap"><h2>How learners rate lessons</h2></div>
            @if ($s['lessons']['total'] === 0 && $s['courses']['total'] === 0)
                <p class="hm-note" style="margin-top:0">No published reviews yet. Reviews come only from learners who took the lesson or the course.</p>
            @else
                <dl class="hm-facts">
                    <dt>Lessons</dt><dd class="hm-big">{{ $dash($s['lessons']['average']) }} <small>average of {{ $s['lessons']['total'] }} published lesson reviews</small></dd>
                    <dt>Courses</dt><dd class="hm-plain">{{ $s['courses']['total'] === 0 ? 'No published reviews yet' : $s['courses']['average'].' average of '.$s['courses']['total'].' reviews' }}</dd>
                </dl>
                @include('ops.partials.bars', ['rows' => collect($s['lessons']['distribution'])->map(fn ($r) => ['label' => $r['stars'].' of 5', 'value' => $r['n']])->all(), 'label' => 'Lesson reviews by rating'])
            @endif
        </section>
    </div>

    <p class="hm-foot">
        <span><b>{{ $waiting->sum('count') }}</b> {{ Str::plural('item', $waiting->sum('count')) }} waiting</span>
        <span><b>{{ $waiting->count() }}</b> {{ Str::plural('queue', $waiting->count()) }}</span>
        <span>Window: last {{ $window }} days</span>
    </p>
    </div>
</x-layouts::operations>
