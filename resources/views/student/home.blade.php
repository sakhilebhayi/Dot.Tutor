@php
    use App\Domain\Student\Support\Times;
    $young = $ctx['step'] === 'young';
    $first = \Illuminate\Support\Str::of(auth()->user()->name)->before(' ');
    $level = $ctx['level'];
@endphp
<x-student.shell title="Home">
    <x-td.page-head :title="$young ? 'Hi '.$first : 'Your learning'"
        :lede="$level ? $level->name.', '.$level->curriculum->label() : 'Add your grade or level so help fits you.'">
        <x-slot:actions>
            @if ($level)
                <x-td.button variant="quiet" icon="graduation-cap" :href="route('academic.profile.edit')">Learning profile</x-td.button>
            @endif
        </x-slot:actions>
    </x-td.page-head>

    @if ($ctx['suspended'])
        <x-td.notice tone="danger" title="Your account is paused">An operator paused this account. You can read, but booking, paying and messaging are off. <a class="td-link" href="{{ route('safety.account') }}">See why</a>.</x-td.notice>
    @elseif ($ctx['limited'])
        <x-td.notice tone="warning" title="A parent or guardian needs to join you first">
            Before you can book a lesson, pay or message a tutor, a parent or guardian links to your account and says yes. You can still look around and practise.
            <div class="st-inline" style="margin-top:var(--td-space-3)"><x-td.button size="sm" variant="primary" :href="route('safety.account')" icon="hand-heart">Invite a parent or guardian</x-td.button></div>
        </x-td.notice>
    @endif

    @unless ($ctx['onboarded'])
        <x-td.notice tone="info" title="Tell us about your learning">
            Your grade, subjects and the languages you learn best in. It takes about a minute, and every part except the grade can be skipped.
            <div class="st-inline" style="margin-top:var(--td-space-3)"><x-td.button size="sm" variant="primary" :href="route('learn.onboarding')" icon-end="arrow-right">Start</x-td.button></div>
        </x-td.notice>
    @endunless

    {{-- The one next thing. --}}
    @if ($next)
        <section class="st-next" aria-labelledby="next-title">
            @switch($next['kind'])
                @case('pay')
                    <p class="st-next__when">Waiting for you · {{ Times::relative($next['lesson']->starts_at) }}</p>
                    <h2 class="st-next__what" id="next-title">Pay to confirm {{ $next['lesson']->subject?->name ?? 'your lesson' }} with {{ $next['lesson']->tutorProfile->displayName() }}</h2>
                    <div class="st-inline"><x-td.button variant="primary" :href="route('learn.lessons.show', $next['lesson'])" icon-end="arrow-right">Open the lesson</x-td.button></div>
                    @break
                @case('lesson')
                    <p class="st-next__when">Next lesson · {{ Times::relative($next['lesson']->starts_at) }}</p>
                    <h2 class="st-next__what" id="next-title">{{ $next['lesson']->subject?->name ?? 'Lesson' }} with {{ $next['lesson']->tutorProfile->displayName() }}</h2>
                    <p class="st-muted" style="margin:0">{{ app(\App\Domain\Marketplace\Services\BookingPresenter::class)->nextStep($next['lesson']) }}</p>
                    <div class="st-inline"><x-td.button variant="primary" :href="route('learn.lessons.show', $next['lesson'])" icon-end="arrow-right">Open the lesson</x-td.button></div>
                    @break
                @case('work')
                    <p class="st-next__when">Set by your tutor @if ($next['work']['due_at']) · due {{ Times::relative($next['work']['due_at']) }} @endif</p>
                    <h2 class="st-next__what" id="next-title">{{ $next['work']['title'] }}</h2>
                    <div class="st-inline"><x-td.button variant="primary" :href="route('learning.assessments.take', $next['work']['assignment']->assessment_id)" icon-end="arrow-right">Start</x-td.button></div>
                    @break
                @case('course')
                    <p class="st-next__when">Carry on where you stopped · {{ $next['course']['summary']['completed'] }} of {{ $next['course']['summary']['total'] }} lessons done</p>
                    <h2 class="st-next__what" id="next-title">{{ $next['course']['title'] }}</h2>
                    <div class="st-inline"><x-td.button variant="primary" :href="route('learning.learn', [$next['course']['enrolment']->course_id, $next['course']['summary']['resume_lesson_id']])" icon-end="arrow-right">Resume</x-td.button></div>
                    @break
            @endswitch
        </section>
    @endif

    {{-- Tell us what you need. --}}
    <form method="POST" action="{{ route('learn.need') }}" class="st-slip" role="search" aria-labelledby="need-label">
        @csrf
        <label class="st-slip__label" id="need-label" for="need">{{ $young ? 'What do you need help with?' : 'Tell us what you need' }}</label>
        <div class="st-slip__row">
            <x-td.input name="need" :hint="true" maxlength="500" autocomplete="off"
                placeholder="{{ $young ? 'Maths, Grade 3' : 'Grade 11 Maths in isiZulu, after school, under R250' }}" />
            <x-td.button type="submit" variant="primary" icon="search">{{ $young ? 'Get help' : 'Find help' }}</x-td.button>
        </div>
        <p class="td-hint" id="need-hint">
            Say it in your own words. We read the grade, subject, language and budget, show you what we understood, and explain why each tutor fits.
            @if (Route::has('ai.need.form') && $aiConfigured)
                If we miss something, <a class="td-link" href="{{ route('ai.need.form') }}">the AI assistant can reword it</a>.
            @endif
        </p>
        @error('need')<p class="td-error" id="need-error"><x-td.icon name="circle-alert" />{{ $message }}</p>@enderror
    </form>

    <div class="st-cols">
        <div class="st-stack">
            <x-td.panel title="Lessons coming up" flush>
                <x-slot:actions>
                    <x-td.button variant="quiet" size="sm" :href="route('marketplace.bookings')">All lessons</x-td.button>
                </x-slot:actions>
                @if ($lessons->isEmpty() && $seats->isEmpty())
                    <x-td.state kind="empty" title="No lessons booked">
                        Find a tutor for one lesson at a time. There is no subscription and nothing to sign up for.
                        <x-slot:actions><x-td.button :href="route('marketplace.find')" icon="search">Find a tutor</x-td.button></x-slot:actions>
                    </x-td.state>
                @else
                    <ul class="st-list">
                        @foreach ($lessons as $lesson)
                            <li class="st-row">
                                <div>
                                    <p class="st-row__title"><a href="{{ route('learn.lessons.show', $lesson) }}">{{ $lesson->subject?->name ?? 'Lesson' }} with {{ $lesson->tutorProfile->displayName() }}</a></p>
                                    <p class="st-row__meta"><time datetime="{{ $lesson->starts_at->toIso8601String() }}">{{ Times::when($lesson->starts_at) }}</time> · {{ $lesson->duration_minutes }} min · {{ $lesson->delivery === 'in_person' ? 'In person' : 'Online' }}</p>
                                </div>
                                <div class="st-row__end">@include('student.partials.lesson-state', ['status' => $lesson->status])</div>
                            </li>
                        @endforeach
                        @foreach ($seats as $seat)
                            <li class="st-row">
                                <div>
                                    <p class="st-row__title"><a href="{{ route('learn.lessons.show', $seat->session) }}">{{ $seat->session->subject?->name ?? 'Group lesson' }}, group with {{ $seat->session->tutorProfile->displayName() }}</a></p>
                                    <p class="st-row__meta"><time datetime="{{ $seat->session->starts_at->toIso8601String() }}">{{ Times::when($seat->session->starts_at) }}</time> · your seat</p>
                                </div>
                                <div class="st-row__end">@include('student.partials.lesson-state', ['status' => $seat->status])</div>
                            </li>
                        @endforeach
                    </ul>
                    <p class="st-small" style="padding:0 var(--td-row-pad-x) var(--td-space-3)">{{ Times::zoneNote() }}</p>
                @endif
            </x-td.panel>

            <x-td.panel title="{{ $young ? 'Your courses' : 'Courses in progress' }}" flush>
                <x-slot:actions>
                    @if (Route::has('learn.courses'))<x-td.button variant="quiet" size="sm" :href="route('learn.courses')">My courses</x-td.button>@endif
                </x-slot:actions>
                @if ($courses === [])
                    <x-td.state kind="empty" title="No course in progress">
                        Courses are written by tutors. Some are free.
                        <x-slot:actions><x-td.button :href="route('learning.courses.index')" icon="book-open">Browse courses</x-td.button></x-slot:actions>
                    </x-td.state>
                @else
                    <ul class="st-list">
                        @foreach ($courses as $course)
                            <li class="st-row">
                                <div class="st-stack st-stack--tight">
                                    <p class="st-row__title"><a href="{{ route('learning.learn', [$course['enrolment']->course_id, $course['summary']['resume_lesson_id']]) }}">{{ $course['title'] }}</a></p>
                                    <x-td.progress :label="'Lessons done'" :value="$course['summary']['completed']" :max="max(1, $course['summary']['total'])"
                                        :display="$course['summary']['completed'].' of '.$course['summary']['total']" :stage="$ctx['stage_colour']" />
                                </div>
                                <div class="st-row__end"><x-td.button size="sm" :href="route('learning.learn', [$course['enrolment']->course_id, $course['summary']['resume_lesson_id']])">Resume</x-td.button></div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-td.panel>
        </div>

        <div class="st-stack">
            <x-td.panel title="{{ $young ? 'Work from your tutor' : 'Work due' }}" flush>
                @if ($work === [])
                    <x-td.state kind="empty" title="Nothing due">When a tutor sets you a quiz or test, it shows here with its due date.</x-td.state>
                @else
                    <ul class="st-list">
                        @foreach ($work as $item)
                            <li class="st-row">
                                <div>
                                    <p class="st-row__title"><a href="{{ route('learning.assessments.take', $item['assignment']->assessment_id) }}">{{ $item['title'] }}</a></p>
                                    <p class="st-row__meta">
                                        @if ($item['due_at'])
                                            @if ($item['overdue'])<strong>Past its due date</strong> ({{ Times::when($item['due_at']) }})@else Due {{ Times::relative($item['due_at']) }}@endif
                                        @else
                                            No due date
                                        @endif
                                    </p>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-td.panel>

            <x-td.panel title="What your answers show">
                @if ($insights['sentences'] === [])
                    <p style="margin:0">Answer a few practice questions or a quiz and this says what is going well and what needs more practice, topic by topic.</p>
                @else
                    <ul class="st-stack st-stack--tight" style="margin:0;padding-left:1.1rem;list-style:disc">
                        @foreach (array_slice($insights['sentences'], 0, $young ? 2 : 4) as $sentence)
                            <li>{{ $sentence }}</li>
                        @endforeach
                    </ul>
                    <p class="st-small" style="margin:var(--td-space-3) 0 0">{{ $insights['label'] }}</p>
                @endif
                <div class="st-inline" style="margin-top:var(--td-space-3)">
                    <x-td.button size="sm" variant="quiet" :href="route('learn.progress')" icon-end="arrow-right">Progress</x-td.button>
                    <x-td.button size="sm" variant="quiet" :href="route('practice.index')" icon-end="arrow-right">Practise</x-td.button>
                </div>
            </x-td.panel>

            @if ($toReview->isNotEmpty())
                <x-td.panel title="How did it go?" flush>
                    <ul class="st-list">
                        @foreach ($toReview as $lesson)
                            <li class="st-row">
                                <div>
                                    <p class="st-row__title">{{ $lesson->subject?->name ?? 'Lesson' }} with {{ $lesson->tutorProfile->displayName() }}</p>
                                    <p class="st-row__meta">{{ Times::when($lesson->starts_at) }}</p>
                                </div>
                                <div class="st-row__end"><x-td.button size="sm" :href="route('learn.lessons.show', $lesson).'#review'">Review</x-td.button></div>
                            </li>
                        @endforeach
                    </ul>
                </x-td.panel>
            @endif

            @if (Route::has('ai.index'))
                <x-td.panel title="Study assistant">
                    @if ($aiConfigured)
                        <p style="margin:0">Ask for an explanation, practice questions or a revision plan. Its answers are AI guidance, not marked results.</p>
                        <div class="st-inline" style="margin-top:var(--td-space-3)"><x-td.button size="sm" :href="route('ai.index')" icon="circle-help">Ask the assistant</x-td.button></div>
                    @else
                        <p style="margin:0" class="st-muted">The AI study assistant is not switched on for Dot.Tutor yet. Tutors and the study library can help in the meantime.</p>
                    @endif
                </x-td.panel>
            @endif
        </div>
    </div>
</x-student.shell>
