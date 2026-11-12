{{-- /t/{id} (marketplace.tutors.show): the tutor's storefront (D-1109). $tutor from TutorPresenter::storefront,
     $profile; StorefrontComposer adds $courses, $materials, $reviewTexts, $nextOpen, $languageNames. --}}
@php
    use App\Domain\Student\Support\Times;
    $lessonTypes = ['one_to_one', 'consultation', 'homework_help', 'exam_prep', 'revision'];
    $reviews = $tutor['reviews'];
    $offerings = collect($tutor['offerings']);
@endphp
<x-student.shell :title="$tutor['name']">
    <header class="st-record" style="border:1px solid var(--td-line)">
        <div class="st-record__head">
            <x-td.avatar :name="$tutor['name']" :src="$tutor['photo_url']" size="lg" decorative />
            <div class="st-stack st-stack--tight">
                <h1 class="td-page-head__title" style="margin:0">{{ $tutor['name'] }}</h1>
                @if ($tutor['headline'])<p class="st-muted" style="margin:0">{{ $tutor['headline'] }}</p>@endif
                <div class="st-inline">@include('student.partials.verification', ['verification' => $tutor['verification']])</div>
                <p class="st-small" style="margin:0">{{ $tutor['verification']['description'] }}</p>
            </div>
            @if ($tutor['pricing']['hourly_rate_minor'] !== null)
                <p class="st-record__price" style="margin:0"><x-td.money :minor="$tutor['pricing']['hourly_rate_minor']" :currency="$tutor['pricing']['currency']" per="hour" /></p>
            @endif
        </div>
        <div class="st-inline">
            @if ($offerings->isNotEmpty())<x-td.button variant="primary" href="#offerings" icon="calendar">Book</x-td.button>@endif
            <x-td.button variant="quiet" :href="route('marketplace.find')" icon="arrow-left">Back to tutors</x-td.button>
        </div>
    </header>

    @if ($errors->any())
        <x-td.notice tone="danger" title="That did not go through">{{ $errors->first() }}</x-td.notice>
    @endif

    <div class="st-cols">
        <div class="st-stack">
            <x-td.panel title="About">
                <div class="st-stack">
                    @if ($tutor['introduction'])<p class="st-prose" style="margin:0;white-space:pre-line">{{ $tutor['introduction'] }}</p>@endif
                    @if ($tutor['teaching_approach'])<div class="st-prose"><p style="margin:0"><strong>How I teach.</strong> {{ $tutor['teaching_approach'] }}</p></div>@endif
                    <dl class="st-facts">
                        @if ($tutor['experience']['years'])<dt>Experience</dt><dd>{{ $tutor['experience']['years'] }} {{ \Illuminate\Support\Str::plural('year', (int) $tutor['experience']['years']) }}@if ($tutor['experience']['summary']). {{ $tutor['experience']['summary'] }}@endif</dd>@endif
                        <dt>On Dot.Tutor</dt><dd>{{ $tutor['experience']['sessions_completed'] }} {{ \Illuminate\Support\Str::plural('lesson', $tutor['experience']['sessions_completed']) }} completed</dd>
                        <dt>How</dt><dd>{{ implode(' · ', array_filter([$tutor['formats']['online'] ? 'Online' : null, $tutor['formats']['in_person'] ? 'In person'.($tutor['formats']['in_person_area'] ? ' ('.$tutor['formats']['in_person_area'].')' : '') : null])) ?: 'Not stated' }}</dd>
                    </dl>
                </div>
            </x-td.panel>

            <x-td.panel title="Teaches" flush>
                @if ($tutor['teaching_scope'] === [] && $tutor['subjects'] === [])
                    <x-td.state kind="empty" title="No subjects listed yet" />
                @elseif ($tutor['teaching_scope'] !== [])
                    <x-td.table caption="Subjects and levels {{ $tutor['name'] }} teaches" caption-hidden>
                        <thead><tr><th scope="col">Subject</th><th scope="col">Curriculum</th><th scope="col">Levels</th></tr></thead>
                        <tbody>
                            @foreach ($tutor['teaching_scope'] as $scope)
                                <tr>
                                    <td>{{ $scope['subject']['name'] }}</td>
                                    <td>{{ $scope['curriculum']['name'] ?? 'Not stated' }}</td>
                                    <td>@if ($scope['level_min'] || $scope['level_max']){{ $scope['level_min']['name'] ?? '' }}@if ($scope['level_max'] && ($scope['level_max']['id'] ?? null) !== ($scope['level_min']['id'] ?? null)) to {{ $scope['level_max']['name'] }}@endif @else Not narrowed @endif</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </x-td.table>
                @else
                    <div class="td-panel__body st-inline">
                        @foreach ($tutor['subjects'] as $subject)<x-td.pill>{{ $subject['name'] }}</x-td.pill>@endforeach
                    </div>
                @endif
            </x-td.panel>

            <x-td.panel title="Teaching languages">
                @if ($tutor['languages'] === null)
                    <p style="margin:0" class="st-muted">Teaching languages are not connected on this server.</p>
                @elseif ($tutor['languages'] === [])
                    <p style="margin:0" class="st-muted">{{ $tutor['name'] }} has not listed the languages they teach in.</p>
                @else
                    <div class="st-chipset">
                        @foreach ($tutor['languages'] as $lang)
                            <x-td.language-chip :code="$lang['code']" :name="$lang['name']" :endonym="$languageNames[$lang['code']] ?? $lang['name']" role="teaches" :verified="$lang['verified']" />
                        @endforeach
                    </div>
                    <p class="st-small" style="margin:var(--td-space-3) 0 0">Languages are self-declared. A check mark means Dot.Tutor reviewed that the tutor can teach in it.</p>
                @endif
            </x-td.panel>

            <x-td.panel title="Qualifications" flush>
                @if ($tutor['qualifications'] === [] && ! $tutor['self_declared_qualifications'])
                    <x-td.state kind="empty" title="No qualifications added yet" />
                @else
                    <ul class="st-list">
                        @foreach ($tutor['qualifications'] as $q)
                            <li class="st-row">
                                <div>
                                    <p class="st-row__title">{{ $q['title'] }}</p>
                                    <p class="st-row__meta">{{ $q['kind_label'] }}@if ($q['institution']) · {{ $q['institution'] }}@endif @if ($q['year_awarded']) · {{ $q['year_awarded'] }}@endif</p>
                                </div>
                                <div class="st-row__end">
                                    <span @class(['td-badge', 'td-badge--verified' => $q['verified'], 'td-badge--self' => ! $q['verified']])><x-td.icon :name="$q['verified'] ? 'badge-check' : 'circle-dashed'" />{{ $q['status_label'] }}</span>
                                </div>
                            </li>
                        @endforeach
                        @if ($tutor['self_declared_qualifications'])
                            <li class="st-row">
                                <div><p class="st-row__title" style="font-weight:400;white-space:pre-line">{{ $tutor['self_declared_qualifications']['text'] }}</p></div>
                                <div class="st-row__end"><span class="td-badge td-badge--self"><x-td.icon name="circle-dashed" />{{ $tutor['self_declared_qualifications']['label'] }}</span></div>
                            </li>
                        @endif
                    </ul>
                @endif
            </x-td.panel>

            <x-td.panel title="Reviews">
                @if ($reviews['count'] === 0)
                    <p style="margin:0">No reviews yet. Only learners who finished a lesson with {{ $tutor['name'] }} can review.</p>
                @else
                    <div class="st-stack">
                        <p class="st-title">{{ number_format($reviews['average'], 1) }} out of 5 <span class="st-small">from {{ $reviews['count'] }} {{ \Illuminate\Support\Str::plural('review', $reviews['count']) }} after a finished lesson</span></p>
                        <div class="st-stack st-stack--tight st-young-hide">
                            @foreach ($reviews['distribution'] as $stars => $n)
                                <x-td.progress :label="$stars.' '.\Illuminate\Support\Str::plural('star', $stars)" :value="$n" :max="$reviews['count']" :display="(string) $n" />
                            @endforeach
                        </div>
                        @foreach ($reviewTexts as $review)
                            <blockquote style="margin:0" class="st-stack st-stack--tight">
                                <p style="margin:0">{{ $review->review }}</p>
                                <footer class="st-small">{{ $review->rating }} out of 5 · {{ $review->created_at?->timezone(Times::zone())->format('j M Y') }}</footer>
                                @if ($review->tutor_reply)<p class="st-small" style="margin:0"><strong>Reply from {{ $tutor['name'] }}:</strong> {{ $review->tutor_reply }}</p>@endif
                            </blockquote>
                        @endforeach
                    </div>
                @endif
            </x-td.panel>

            @include('student.partials.report', ['type' => 'tutor_profile', 'id' => $profile->id, 'label' => 'this profile'])
        </div>

        <aside class="st-stack" id="offerings" aria-label="Book with {{ $tutor['name'] }}">
            <x-td.panel title="Lessons and packages" flush>
                @if ($offerings->isEmpty())
                    <x-td.state kind="empty" title="Nothing to book yet">{{ $tutor['name'] }} has not published a lesson or package.</x-td.state>
                @else
                    <ul class="st-list">
                        @foreach ($offerings as $o)
                            <li class="st-row" style="grid-template-columns:minmax(0,1fr)">
                                <div class="st-stack st-stack--tight">
                                    <p class="st-row__title">{{ $o['title'] }}</p>
                                    <p class="st-row__meta">{{ $o['type_label'] }} · {{ $o['duration_minutes'] }} min
                                        @if ($o['type'] === 'package') · {{ $o['package_lessons'] }} lessons @endif
                                        @if ($o['type'] === 'group') · up to {{ $o['capacity'] }} learners @endif
                                        @if ($o['subjects'] !== []) · {{ collect($o['subjects'])->pluck('name')->implode(', ') }}@endif</p>
                                    @if ($o['description'])<p style="margin:0" class="st-small">{{ \Illuminate\Support\Str::limit($o['description'], 160) }}</p>@endif
                                    <p style="margin:0" class="st-title">@if ((int) $o['price_minor'] === 0) Free @else<x-td.money :minor="$o['price_minor']" :currency="$o['currency']" />@endif</p>
                                    <p class="st-small" style="margin:0">{{ $o['cancellation']['summary'] }}</p>
                                    <div class="st-inline" style="margin-top:var(--td-space-1)">
                                        <x-td.button :variant="$loop->first ? 'primary' : 'secondary'" size="sm" :href="route('learn.book', $o['id'])">{{ match (true) {
                                            in_array($o['type'], $lessonTypes, true) => 'Request this lesson',
                                            $o['type'] === 'recurring_weekly' => 'Choose a weekly time',
                                            $o['type'] === 'package' => 'See the package',
                                            default => 'See group lessons',
                                        } }}</x-td.button>
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-td.panel>

            <x-td.panel title="Next open times">
                @if ($nextOpen === [])
                    <p style="margin:0" class="st-muted">No open hours in the next two weeks.</p>
                @else
                    <ul style="margin:0;padding-left:1.1rem;list-style:disc" class="st-stack st-stack--tight">
                        @foreach ($nextOpen as $slot)<li><time datetime="{{ $slot->utc()->toIso8601String() }}">{{ Times::when($slot) }}</time></li>@endforeach
                    </ul>
                    <p class="st-small" style="margin:var(--td-space-2) 0 0">One-hour openings. {{ Times::zoneNote() }}</p>
                @endif
            </x-td.panel>

            @if ($courses->isNotEmpty())
                <x-td.panel title="Courses" flush>
                    <ul class="st-list">
                        @foreach ($courses as $course)
                            <li class="st-row"><div><p class="st-row__title"><a href="{{ route('learning.courses.show', $course) }}">{{ $course->currentVersion?->snapshot['course']['title'] ?? $course->title }}</a></p>
                                <p class="st-row__meta">@if ($course->pricing === 'free') Free @else <x-td.money :minor="$course->priceMinor()" :currency="$course->currency" /> @endif · taught in {{ $languageNames[$course->original_locale] ?? $course->original_locale }}</p></div></li>
                        @endforeach
                    </ul>
                </x-td.panel>
            @endif
            @if ($materials->isNotEmpty())
                <x-td.panel title="Study material" flush>
                    <ul class="st-list">
                        @foreach ($materials as $m)
                            <li class="st-row"><div><p class="st-row__title"><a href="{{ route('learning.library.show', $m) }}">{{ $m->title }}</a></p>
                                <p class="st-row__meta">{{ $m->is_free ? 'Free' : '' }}@unless ($m->is_free)<x-td.money :minor="(int) $m->price_minor" :currency="$m->currency ?? 'ZAR'" />@endunless · {{ $languageNames[$m->language] ?? $m->language }}</p></div></li>
                        @endforeach
                    </ul>
                </x-td.panel>
            @endif
        </aside>
    </div>
</x-student.shell>
