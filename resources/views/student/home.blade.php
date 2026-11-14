@php
    use App\Domain\Student\Support\ComingUp;
    use App\Domain\Student\Support\Times;
    $young = $ctx['step'] === 'young';
    $first = \Illuminate\Support\Str::of(auth()->user()->name)->before(' ');
    $level = $ctx['level'];
    $presenter = app(\App\Domain\Marketplace\Services\BookingPresenter::class);
@endphp
<x-student.shell title="Home">
    @php
        // The head's one primary action, decided from the learner's own records (R-50): what blocks them first, then the
        // one next thing, and only when there is nothing at all, finding a tutor. The headline says the same thing in a
        // true sentence about this learner's state (R-51). No tutor is named in the head.
        $subjectOf = fn ($lesson) => $lesson->subject?->name ?? 'Lesson';
        [$primary, $slip] = match (true) {
            $ctx['suspended'] => [
                ['label' => 'See why your account is paused', 'href' => route('safety.account')],
                ['label' => 'Your account is paused', 'text' => 'You can read, but booking, paying and messaging are off.'],
            ],
            // Age not given yet: the account is protected as a minor's until the short profile says otherwise.
            $ctx['limited'] && ! $ctx['onboarded'] => [
                ['label' => 'Tell us about your learning', 'href' => route('learn.onboarding')],
                ['label' => 'About a minute, once', 'text' => 'Your grade and month of birth come first. Booking opens after that, and everything else can be skipped.'],
            ],
            $ctx['limited'] => [
                ['label' => 'Invite a parent or guardian', 'href' => route('safety.account'), 'icon' => 'hand-heart'],
                ['label' => $young ? 'A grown-up must join first' : 'A parent or guardian needs to join you first', 'text' => $young ? 'Then you can book lessons.' : 'They link to your account and say yes before you can book, pay or message a tutor.'],
            ],
            ($next['kind'] ?? null) === 'pay' => [
                ['label' => 'Pay to confirm your lesson', 'href' => route('learn.lessons.show', $next['lesson'])],
                ['label' => 'Waiting for you', 'text' => $subjectOf($next['lesson']).', '.Times::relative($next['lesson']->starts_at)],
            ],
            ($next['kind'] ?? null) === 'lesson' => [
                ['label' => 'Open your next lesson', 'href' => route('learn.lessons.show', $next['lesson'])],
                ['label' => 'Your next lesson', 'text' => $subjectOf($next['lesson']).', '.Times::relative($next['lesson']->starts_at), 'note' => $presenter->nextStep($next['lesson'])],
            ],
            ($next['kind'] ?? null) === 'work' => [
                ['label' => 'Start your work', 'href' => route('learning.assessments.take', $next['work']['assignment']->assessment_id)],
                ['label' => 'Set by your tutor', 'text' => $next['work']['title'], 'note' => $next['work']['due_at'] ? 'Due '.Times::relative($next['work']['due_at']).'.' : null],
            ],
            ($next['kind'] ?? null) === 'course' => [
                ['label' => 'Resume your course', 'href' => route('learning.learn', [$next['course']['enrolment']->course_id, $next['course']['summary']['resume_lesson_id']])],
                ['label' => 'Carry on where you stopped', 'text' => $next['course']['title'], 'note' => $next['course']['summary']['completed'].' of '.$next['course']['summary']['total'].' lessons done.'],
            ],
            default => [
                ['label' => 'Find a tutor', 'href' => route('marketplace.find'), 'icon' => 'search'],
                ['label' => $young ? 'No lessons yet' : 'Nothing booked yet', 'text' => $young ? 'Find a tutor to start.' : 'You have no lessons, set work or courses in progress.'],
            ],
        };

        // The headline: one true sentence built from the same record as the action.
        $nextLesson = $next['lesson'] ?? null;
        [$headline, $sub] = match (true) {
            $ctx['suspended'] => ['Your account is paused.', $slip['text']],
            $ctx['limited'] && ! $ctx['onboarded'] => ['Tell us about your learning.', $slip['text']],
            $ctx['limited'] => [($young ? 'A grown-up must join first.' : 'A parent or guardian needs to join you first.'), $slip['text'].($young ? ' You can still look around and practise.' : '')],
            ($next['kind'] ?? null) === 'pay' => [$subjectOf($nextLesson).' is '.Times::relative($nextLesson->starts_at).', waiting for your payment.', $presenter->nextStep($nextLesson)],
            ($next['kind'] ?? null) === 'lesson' => [$subjectOf($nextLesson).' is '.Times::relative($nextLesson->starts_at).($nextLesson->status === 'pending' ? ', waiting for the tutor' : '').'.', $presenter->nextStep($nextLesson)],
            ($next['kind'] ?? null) === 'work' => [$next['work']['title'].($next['work']['due_at'] ? ' is due '.Times::relative($next['work']['due_at']).'.' : ' is waiting for you.'), 'Set by your tutor.'],
            ($next['kind'] ?? null) === 'course' => ['Carry on with '.$next['course']['title'].'.', $next['course']['summary']['completed'].' of '.$next['course']['summary']['total'].' lessons done.'],
            default => ['Nothing is booked yet.', $young ? 'Find a tutor to start.' : 'Find a tutor for one lesson at a time. There is no subscription.'],
        };
        if ($young) {
            $sub = 'Hi '.$first.'. '.$sub;
        }

        $rows = ComingUp::rows($lessons, $seats, $work, $courses, $toReview, $last, $young ? 3 : 6);
        $dated = collect($rows)->reject(fn ($r) => $r['kind'] === 'last');
    @endphp
    <div class="hm">
    <x-td.home-head :headline="$headline" :sub="$sub" :primary="$primary">
        @if ($level)
            <x-td.button variant="quiet" icon="graduation-cap" :href="route('academic.profile.edit')">Learning profile: {{ $level->name }}, {{ $level->curriculum->label() }}</x-td.button>
        @else
            <x-td.button variant="quiet" icon="graduation-cap" :href="route('learn.onboarding')">Add your grade or level</x-td.button>
        @endif
    </x-td.home-head>

    @if ($ctx['limited'] && ! $ctx['onboarded'])
        {{-- Two things block: the profile (the primary action) and the consent. The consent is the one notice. --}}
        <div class="hm-notice" role="note">
            <x-td.icon name="triangle-alert" />
            <p>{{ $young ? 'A grown-up who looks after you must join first. Then you can book lessons.' : 'Before you can book a lesson, pay or message a tutor, a parent or guardian links to your account and says yes.' }} <a class="td-link" href="{{ route('safety.account') }}">Invite a parent or guardian</a></p>
        </div>
    @endif

    @if (! $ctx['onboarded'] && ! $ctx['limited'] && ! $ctx['suspended'])
        {{-- Adult, no profile yet: a quiet note, never a second primary action (the head already has one). --}}
        <div class="hm-notice" role="note">
            <x-td.icon name="info" />
            <p><strong>Tell us about your learning.</strong> Your grade, subjects and the languages you learn best in. It takes about a minute, and every part except the grade can be skipped. <a class="td-link" href="{{ route('learn.onboarding') }}">Start</a></p>
        </div>
    @endif

    {{-- Tell us what you need: a guided need-reader, not a search box (the shared search pill in the bar is the finder). --}}
    <form method="POST" action="{{ route('learn.need') }}" class="hm-find" role="search" aria-labelledby="need-label">
        @csrf
        <label id="need-label" for="need">{{ $young ? 'What do you need help with?' : 'Tell us what you need' }}</label>
        <x-td.input name="need" :hint="true" maxlength="500" autocomplete="off"
            placeholder="{{ $young ? 'Maths, '.($level?->name ?? 'my grade') : 'Grade 11 Maths in isiZulu, after school, under R250' }}" />
        <x-td.button type="submit" icon="search">{{ $young ? 'Get help' : 'Find help' }}</x-td.button>
        <p class="hm-find__hint" id="need-hint">
            @if ($young)
                Say it your way. We show you tutors who can help, and why.
            @else
                Say it in your own words. We read the grade, subject, language and budget, show you what we understood, and explain why each tutor fits.
            @endif
            @if (Route::has('ai.need.form') && $aiConfigured && ! $young)
                If we miss something, <a class="td-link" href="{{ route('ai.need.form') }}">the AI assistant can reword it</a>.
            @endif
        </p>
        @error('need')<p class="td-error hm-find__error" id="need-error"><x-td.icon name="circle-alert" />{{ $message }}</p>@enderror
    </form>

    <div @class(['hm-split' => ! $young, 'hm-one' => $young])>
        <section aria-labelledby="coming-title">
            <div class="hm-sech">
                <h2 id="coming-title">Coming up</h2>
                @if ($dated->isNotEmpty())<span class="hm-sech__n">{{ $dated->count() }} {{ \Illuminate\Support\Str::plural('item', $dated->count()) }}</span>@endif
                <x-td.button variant="quiet" size="sm" :href="route('marketplace.bookings')">All lessons</x-td.button>
            </div>
            @if ($rows === [])
                <div class="hm-empty">
                    <x-td.shelf />
                    <p>{{ $young ? 'No lessons yet. A tutor can only be booked after a grown-up says yes.' : 'No lessons booked. Find a tutor for one lesson at a time. There is no subscription and nothing to sign up for.' }}</p>
                </div>
            @else
                <ul class="hm-rows">
                    @foreach ($rows as $row)
                        <li class="hm-row">
                            <div>
                                <a class="hm-row__link" href="{{ $row['href'] }}">{{ $row['title'] }}</a>
                                <span class="hm-row__meta">@if ($row['at'] !== null && in_array($row['kind'], ['lesson', 'seat', 'review'], true))<time datetime="{{ $row['at']->toIso8601String() }}">{{ $row['meta'] }}</time>@else{{ $row['meta'] }}@endif</span>
                            </div>
                            <div class="hm-row__end">
                                @if ($row['tag'])<x-td.home-tag :text="$row['tag']['text']" :icon="$row['tag']['icon']" :tone="$row['tag']['tone']" />@endif
                                @if ($row['action'])<x-td.button size="sm" :href="$row['action']['href']">{{ $row['action']['label'] }}</x-td.button>@endif
                            </div>
                        </li>
                    @endforeach
                </ul>
                @if ($lessons->isNotEmpty() || $seats->isNotEmpty())<p class="hm-note">{{ Times::zoneNote() }}</p>@endif
            @endif

            @if ($young)
                <div class="hm-sech hm-gap"><h2>Practise</h2></div>
                <ul class="hm-rows">
                    <li class="hm-row">
                        <div><a class="hm-row__link" href="{{ route('practice.index') }}">Practise</a><span class="hm-row__meta">Questions and cards, at your pace.</span></div>
                        <div class="hm-row__end"><x-td.button size="sm" :href="route('practice.index')">Practise</x-td.button></div>
                    </li>
                    @if ($courses === [])
                        <li class="hm-row">
                            <div><a class="hm-row__link" href="{{ route('learning.courses.index') }}">Your courses</a><span class="hm-row__meta">No course yet</span></div>
                            <div class="hm-row__end"><x-td.button size="sm" :href="route('learning.courses.index')">Browse courses</x-td.button></div>
                        </li>
                    @endif
                </ul>
            @endif
        </section>

        @unless ($young)
            <section aria-labelledby="next-title">
                @if ($next && in_array($next['kind'], ['pay', 'lesson'], true))
                    @php $nl = $next['lesson']; @endphp
                    <div class="hm-sech"><h2 id="next-title">The next thing</h2></div>
                    <dl class="hm-facts">
                        <dt>Subject</dt><dd>{{ $nl->subject?->name ?? 'Lesson' }}</dd>
                        <dt>Tutor</dt><dd>{{ $nl->tutorProfile->displayName() }}</dd>
                        <dt>When</dt><dd>{{ Times::stamp($nl->starts_at) }}</dd>
                        <dt>Length</dt><dd>{{ $nl->duration_minutes }} min, {{ $nl->delivery === 'in_person' ? 'in person' : 'online' }}</dd>
                        <dt>State</dt><dd>@include('student.partials.lesson-state', ['status' => $nl->status])</dd>
                        <dt>What happens next</dt><dd class="hm-plain">{{ $presenter->nextStep($nl) }}</dd>
                    </dl>
                @elseif ($next && $next['kind'] === 'work')
                    <div class="hm-sech"><h2 id="next-title">The next thing</h2></div>
                    <dl class="hm-facts">
                        <dt>Work</dt><dd>{{ $next['work']['title'] }}</dd>
                        <dt>Set by</dt><dd>Your tutor</dd>
                        <dt>Due</dt><dd>{{ $next['work']['due_at'] ? Times::stamp($next['work']['due_at']) : 'No due date' }}</dd>
                    </dl>
                @elseif ($next && $next['kind'] === 'course')
                    <div class="hm-sech"><h2 id="next-title">The next thing</h2></div>
                    <dl class="hm-facts">
                        <dt>Course</dt><dd>{{ $next['course']['title'] }}</dd>
                        <dt>Progress</dt><dd>{{ $next['course']['summary']['completed'] }} of {{ $next['course']['summary']['total'] }} lessons done</dd>
                    </dl>
                @else
                    <div class="hm-sech"><h2 id="next-title">Courses</h2></div>
                    <div class="hm-empty">
                        <p>Courses are written by tutors. Some are free.</p>
                        <x-td.button size="sm" :href="route('learning.courses.index')" icon="book-open">Browse courses</x-td.button>
                    </div>
                @endif

                <div class="hm-sech hm-gap">
                    <h2>What your answers show</h2>
                    <x-td.button variant="quiet" size="sm" :href="route('learn.progress')">Progress</x-td.button>
                </div>
                @if ($insights['sentences'] === [])
                    <p class="hm-note" style="margin-top:0">Answer a few practice questions or a quiz and this says what is going well and what needs more practice, topic by topic.</p>
                @else
                    <ul class="hm-rows">
                        @foreach (array_slice($insights['sentences'], 0, 4) as $sentence)
                            <li class="hm-row hm-row--dense"><span>{{ $sentence }}</span></li>
                        @endforeach
                    </ul>
                    <p class="hm-note">{{ $insights['label'] }}</p>
                @endif

                @if (Route::has('rooms.index'))
                    <div class="hm-sech hm-gap"><h2>Questions in your rooms</h2><x-td.button variant="quiet" size="sm" :href="route('rooms.index')">My rooms</x-td.button></div>
                    <ul class="hm-rows">
                        @forelse ($questions as $question)
                            <li class="hm-row hm-row--dense">
                                <div><a class="hm-row__link" href="{{ route('rooms.posts.show', $question) }}">{{ $question->title ?: \Illuminate\Support\Str::limit($question->body, 80) }}</a><span class="hm-row__meta">{{ $question->room?->displayName() ?? 'Room' }} · {{ Times::when($question->created_at) }}</span></div>
                            </li>
                        @empty
                            <li class="hm-row hm-row--dense hm-row--empty"><span>No unanswered questions in your rooms. Helping someone is a good way to learn.</span></li>
                        @endforelse
                    </ul>
                @endif

                @if (Route::has('ai.index') && $aiConfigured)
                    <div class="hm-sech hm-gap"><h2>Study assistant</h2><x-td.button variant="quiet" size="sm" :href="route('ai.index')" icon="circle-help">Ask the assistant</x-td.button></div>
                    <p class="hm-note" style="margin-top:0">Ask for an explanation, practice questions or a revision plan. Its answers are AI guidance, not marked results.</p>
                @endif
            </section>
        @endunless
    </div>

    <p class="hm-foot">
        <span><b>{{ $lessons->count() + $seats->count() }}</b> {{ \Illuminate\Support\Str::plural('lesson', $lessons->count() + $seats->count()) }} coming up</span>
        <span><b>{{ count($work) }}</b> {{ count($work) === 1 ? 'piece' : 'pieces' }} of work due</span>
        <span><b>{{ count($courses) }}</b> {{ \Illuminate\Support\Str::plural('course', count($courses)) }} in progress</span>
        <span>{{ Times::zoneNote() }}</span>
    </p>
    </div>
</x-student.shell>
