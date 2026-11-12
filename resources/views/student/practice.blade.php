@php
    use App\Domain\Student\Support\Times;
    $young = $ctx['step'] === 'young';
@endphp
<x-student.shell title="Practice">
    <x-td.page-head title="Practice" :lede="$young ? 'Quizzes, work from your tutor, and cards to help you remember.' : 'Work from your tutors, quizzes in your courses, and flashcards that come back when you are about to forget them.'">
        <x-slot:actions><x-td.button variant="quiet" icon="chart-no-axes-column" :href="route('learn.progress')">Progress</x-td.button></x-slot:actions>
    </x-td.page-head>

    @if ($errors->any())
        <x-td.notice tone="danger" title="That did not go through">@foreach ($errors->all() as $m)<p style="margin:0">{{ $m }}</p>@endforeach</x-td.notice>
    @endif

    <div class="st-cols">
        <div class="st-stack">
            <x-td.panel title="Work from your tutors" flush>
                @if ($assignments->isEmpty())
                    <x-td.state kind="empty" title="Nothing set yet">When a tutor you have lessons with sets a quiz or a test, it shows here.</x-td.state>
                @else
                    <ul class="st-list">
                        @foreach ($assignments as $a)
                            @php $done = $latestByAssessment[$a->assessment_id] ?? null; @endphp
                            <li class="st-row">
                                <div>
                                    <p class="st-row__title">{{ $a->assessment->title }}</p>
                                    <p class="st-row__meta">
                                        @if ($a->due_at) Due {{ Times::when($a->due_at) }} · @endif
                                        @if ($a->assessment->time_limit_minutes) {{ $a->assessment->time_limit_minutes }} minutes · @endif
                                        @if ($done) Handed in: {{ $done->score }} out of {{ $done->max_score }} @else Not started @endif
                                    </p>
                                </div>
                                <div class="st-row__end">
                                    @if ($done)<x-td.button size="sm" variant="quiet" :href="route('learning.attempts.show', $done)">See answers</x-td.button>@endif
                                    <x-td.button size="sm" :variant="$done ? 'secondary' : 'primary'" :href="route('learning.assessments.take', $a->assessment_id)">{{ $done ? 'Try again' : 'Start' }}</x-td.button>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-td.panel>

            <x-td.panel title="Quizzes in your courses" flush>
                @if ($quizzes->isEmpty())
                    <x-td.state kind="empty" title="No course quizzes">Courses you join may have quizzes; they appear here and in the course itself.</x-td.state>
                @else
                    <ul class="st-list">
                        @foreach ($quizzes as $quiz)
                            @php $done = $latestByAssessment[$quiz->id] ?? null; @endphp
                            <li class="st-row">
                                <div>
                                    <p class="st-row__title">{{ $quiz->title }}</p>
                                    <p class="st-row__meta">{{ $quiz->course?->title }}@if ($done) · last time {{ $done->score }} out of {{ $done->max_score }}@endif</p>
                                </div>
                                <div class="st-row__end"><x-td.button size="sm" :href="route('learning.assessments.take', $quiz)">{{ $done ? 'Try again' : 'Start' }}</x-td.button></div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-td.panel>

            @if ($attempts->isNotEmpty())
                <x-td.panel title="Your recent answers" flush>
                    <ul class="st-list">
                        @foreach ($attempts->take($young ? 3 : 8) as $attempt)
                            <li class="st-row">
                                <div>
                                    <p class="st-row__title">{{ $attempt->assessment?->title }}</p>
                                    <p class="st-row__meta">{{ Times::when($attempt->submitted_at) }} · {{ $attempt->score }} out of {{ $attempt->max_score }}@if ($attempt->late) · handed in late @endif</p>
                                </div>
                                <div class="st-row__end"><x-td.button size="sm" variant="quiet" :href="route('learning.attempts.show', $attempt)">See answers</x-td.button></div>
                            </li>
                        @endforeach
                    </ul>
                </x-td.panel>
            @endif
        </div>

        <div class="st-stack">
            <x-td.panel title="Your flashcards" flush>
                @if ($mine->isEmpty())
                    <x-td.state kind="empty" title="No cards yet">Write a question on one side and the answer on the other. Cards you get wrong come back sooner.</x-td.state>
                @else
                    <ul class="st-list">
                        @foreach ($mine as $row)
                            <li class="st-row">
                                <div>
                                    <p class="st-row__title">{{ $row['set']->title }}</p>
                                    <p class="st-row__meta">{{ $row['set']->cards_count }} {{ \Illuminate\Support\Str::plural('card', $row['set']->cards_count) }} · {{ $row['due'] === 0 ? 'nothing due' : $row['due'].' to review' }}</p>
                                </div>
                                <div class="st-row__end"><x-td.button size="sm" :variant="$row['due'] > 0 ? 'primary' : 'secondary'" :href="route('practice.cards.show', $row['set']->id)">{{ $row['due'] > 0 ? 'Review' : 'Open' }}</x-td.button></div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-td.panel>

            <x-td.panel title="Make a set of cards">
                <form method="POST" action="{{ route('practice.cards.store') }}" class="st-form">
                    @csrf
                    <x-td.field label="Name of the set" for="title"><x-td.input name="title" required maxlength="160" placeholder="Times tables 6 to 9" /></x-td.field>
                    <x-td.field label="Subject" for="subject_id" optional>
                        <x-td.select name="subject_id" placeholder="No subject" :options="$subjects->all()" />
                    </x-td.field>
                    {{-- Two fields per card (R-47 polish D-2021): the old "question | answer" lines were unusable for a child. --}}
                    @php $oldCards = array_values(array_filter((array) old('card', []), fn ($c) => is_array($c))); $rowsShown = max(4, count($oldCards)); @endphp
                    <fieldset class="st-cards-editor" data-cards-editor aria-describedby="cards-hint">
                        <legend>Cards</legend>
                        <p class="td-hint" id="cards-hint" style="margin:0">{{ $young ? 'Write a question, then its answer. Empty cards are left out.' : 'A question or prompt on the front, the answer on the back. Empty rows are left out.' }}</p>
                        @error('cards')<p class="td-error"><x-td.icon name="circle-alert" />{{ $message }}</p>@enderror
                        <ol class="st-cards-editor__list" data-cards-list>
                            @for ($i = 0; $i < $rowsShown; $i++)
                                <li class="st-cards-editor__row" data-card-row>
                                    <span class="st-cards-editor__n" aria-hidden="true" data-card-number>{{ $i + 1 }}</span>
                                    <x-td.field label="Front" for="card-{{ $i }}-front"><x-td.input name="card[{{ $i }}][front]" id="card-{{ $i }}-front" maxlength="500" :value="$oldCards[$i]['front'] ?? ''" :placeholder="$i === 0 ? '7 x 8' : null" /></x-td.field>
                                    <x-td.field label="Back" for="card-{{ $i }}-back"><x-td.input name="card[{{ $i }}][back]" id="card-{{ $i }}-back" maxlength="500" :value="$oldCards[$i]['back'] ?? ''" :placeholder="$i === 0 ? '56' : null" /></x-td.field>
                                </li>
                            @endfor
                        </ol>
                        <x-td.button type="button" variant="quiet" size="sm" icon="plus" data-cards-add hidden>Add another card</x-td.button>
                    </fieldset>
                    @unless ($ctx['minor'])
                        <x-td.check name="is_shared" value="1">Let other learners use this set</x-td.check>
                    @endunless
                    <div class="st-form__actions"><x-td.button type="submit" variant="primary">Save the cards</x-td.button></div>
                </form>
            </x-td.panel>

            @if ($shared->isNotEmpty())
                <x-td.panel title="Sets other learners shared" flush>
                    <ul class="st-list">
                        @foreach ($shared as $set)
                            <li class="st-row">
                                <div><p class="st-row__title">{{ $set->title }}</p><p class="st-row__meta">{{ $set->cards_count }} {{ \Illuminate\Support\Str::plural('card', $set->cards_count) }}</p></div>
                                <div class="st-row__end"><x-td.button size="sm" :href="route('practice.cards.show', $set->id)">Review</x-td.button></div>
                            </li>
                        @endforeach
                    </ul>
                </x-td.panel>
            @endif
        </div>
    </div>
</x-student.shell>
