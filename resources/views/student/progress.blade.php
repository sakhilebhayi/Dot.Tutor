@php
    use App\Domain\Student\Support\Times;
    $young = $ctx['step'] === 'young';
    $levelWords = [
        'secure' => ['Going well', 'circle-check', 'strong'],
        'developing' => ['Getting there', 'circle-dashed', ''],
        'needs_attention' => ['Needs more practice', 'circle-alert', 'attention'],
        'not_enough_evidence' => ['Too few answers to say', 'circle-help', ''],
    ];
    $trendWords = ['improving' => 'better than before', 'declining' => 'slipping lately', 'steady' => 'steady', 'unknown' => ''];
@endphp
<x-student.shell title="Progress">
    <x-td.page-head title="Progress" :lede="$young ? 'What is going well and what to practise next.' : 'What your answers show, topic by topic. Worked out from your marked answers, not from guesses.'">
        <x-slot:actions><x-td.button variant="primary" icon="list-checks" :href="route('practice.index')">Practise</x-td.button></x-slot:actions>
    </x-td.page-head>

    <section class="st-next" aria-labelledby="insight-title">
        <h2 class="st-title" id="insight-title">In a few words</h2>
        @if ($insights['sentences'] === [])
            <p style="margin:0">Nothing to say yet. Once you have answered at least three questions in a topic, this tells you how it is going.</p>
        @else
            <ul class="st-stack st-stack--tight" style="margin:0;padding-left:1.1rem;list-style:disc;font-size:var(--td-text-md)">
                @foreach ($insights['sentences'] as $sentence)<li>{{ $sentence }}</li>@endforeach
            </ul>
        @endif
        <p class="st-small" style="margin:0">{{ $insights['label'] }}@if (($insights['based_on']['answers'] ?? 0) > 0) Based on {{ $insights['based_on']['answers'] }} {{ \Illuminate\Support\Str::plural('answer', $insights['based_on']['answers']) }} in {{ $insights['based_on']['attempts'] }} {{ \Illuminate\Support\Str::plural('attempt', $insights['based_on']['attempts']) }}.@endif</p>
    </section>

    @if ($bySubject->isEmpty())
        <x-td.state kind="empty" title="No topics yet">@if ($young)Your quizzes fill this in. Cards help you remember. They do not count here.@else Quizzes in your courses and work from your tutors fill this in. Flashcards help you remember, but they are not marked, so they do not count here.@endif
            <x-slot:actions><x-td.button :href="route('practice.index')" icon="list-checks">Go to practice</x-td.button></x-slot:actions>
        </x-td.state>
    @else
        @foreach ($bySubject as $subject => $rows)
            <x-td.panel :title="$subject" flush>
                <ul class="st-list">
                    @foreach ($rows as $row)
                        @php [$word, $icon, $tone] = $levelWords[$row['level']] ?? [ucfirst($row['level']), 'info', '']; @endphp
                        <li class="st-mastery">
                            <div>
                                <p class="st-row__title">{{ $row['topic'] }}</p>
                                @if ($row['strand'] && ! $young)<p class="st-row__meta">{{ $row['strand'] }}</p>@endif
                            </div>
                            <p style="margin:0" @class(['st-level', 'st-level--'.$tone => $tone !== ''])><x-td.icon :name="$icon" />{{ $word }}@if (($trendWords[$row['trend']] ?? '') !== '' && $row['level'] !== 'not_enough_evidence')<span class="st-small" style="font-weight:400">, {{ $trendWords[$row['trend']] }}</span>@endif</p>
                            <p class="st-small st-young-hide" style="margin:0">{{ $row['answered'] }} {{ \Illuminate\Support\Str::plural('answer', $row['answered']) }}@if ($row['recent_accuracy_percent'] !== null) · {{ $row['recent_accuracy_percent'] }}% right in the last {{ min(10, $row['answered']) }}@endif</p>
                        </li>
                    @endforeach
                </ul>
            </x-td.panel>
        @endforeach
    @endif

    <x-td.panel title="What you did lately" flush>
        @if ($recent === [])
            <x-td.state kind="empty" title="Nothing yet" />
        @else
            <ul class="st-list">
                @foreach ($recent as $event)
                    <li class="st-row">
                        <div>
                            <p class="st-row__title" style="font-weight:400">{{ $event['title'] }}</p>
                            <p class="st-row__meta">{{ Times::when($event['at']) }}@if (isset($event['detail']['percent']) && $event['detail']['percent'] !== null) · {{ $event['detail']['percent'] }}%@endif</p>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-td.panel>
    <p class="st-small" style="margin:0">There are no rankings or leaderboards on Dot.Tutor.</p>
</x-student.shell>
