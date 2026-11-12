{{-- /learning/courses/{course} (learning.courses.show): price, objectives, workload and refund policy before purchase
     (D-1118). From the learning controller: $course, $data (CoursePresenter + viewer), $quote. --}}
@php
    $viewer = $data['viewer'];
    $language = $languageNames[$data['language']] ?? $data['language'];
    $hours = $data['workload_minutes'] ? max(1, (int) round($data['workload_minutes'] / 60)) : null;
    $lessonCount = collect($data['outline'])->sum(fn ($s) => count($s['lessons']));
@endphp
<x-student.shell :title="$data['title']">
    <x-td.page-head :title="$data['title']" :lede="'By '.$data['tutor']['name'].' · '.($data['academic']['subject'] ?? 'Subject not set').(($data['academic']['level'] ?? null) ? ' · '.$data['academic']['level'] : '')">
        <x-slot:actions><x-td.button variant="quiet" icon="arrow-left" :href="route('learning.courses.index')">All courses</x-td.button></x-slot:actions>
    </x-td.page-head>

    @if ($errors->any())<x-td.notice tone="danger" title="That did not go through">@foreach ($errors->all() as $m)<p style="margin:0">{{ $m }}</p>@endforeach</x-td.notice>@endif

    <div class="st-cols st-course">
        <div class="st-stack">
            <x-td.panel title="About this course">
                <div class="st-stack">
                    <p class="st-original" style="margin:0"><x-td.icon name="languages" />Written in {{ $language }} (the original language)
                        @if (Route::has('ai.translations.show') && $data['description'])· <a class="td-link" href="{{ route('ai.translations.show', ['learning.course', $course->id, 'description']) }}">Read it in another language</a>@endif</p>
                    <p class="st-prose" style="margin:0;white-space:pre-line">{{ $data['description'] }}</p>
                    <dl class="st-facts">
                        <dt>Lessons</dt><dd>{{ $lessonCount }}</dd>
                        @if ($hours)<dt>Workload</dt><dd>About {{ $hours }} {{ \Illuminate\Support\Str::plural('hour', $hours) }} in all</dd>@endif
                        @if ($data['academic']['curriculum'] ?? null)<dt>Curriculum</dt><dd>{{ $data['academic']['curriculum'] }}</dd>@endif
                        <dt>Reviews</dt><dd>@if ($data['reviews']['count'] > 0){{ $data['reviews']['average'] }} out of 5 from {{ $data['reviews']['count'] }} enrolled {{ \Illuminate\Support\Str::plural('learner', $data['reviews']['count']) }}@else None yet @endif</dd>
                    </dl>
                </div>
            </x-td.panel>
            @if ($data['objectives'])
                <x-td.panel title="You will learn to">
                    <ul class="st-stack st-stack--tight" style="margin:0;padding-left:1.1rem;list-style:disc">@foreach ($data['objectives'] as $objective)<li>{{ $objective }}</li>@endforeach</ul>
                </x-td.panel>
            @endif
            @if ($data['prerequisites'])
                <x-td.panel title="Before you start">
                    <ul class="st-stack st-stack--tight" style="margin:0;padding-left:1.1rem;list-style:disc">@foreach ($data['prerequisites'] as $requirement)<li>{{ $requirement }}</li>@endforeach</ul>
                </x-td.panel>
            @endif
            <x-td.panel title="Course content" flush>
                <div class="td-panel__body">
                    <ol class="st-outline">
                        @foreach ($data['outline'] as $section)
                            <li>
                                <p class="st-outline__section">{{ $section['title'] }}</p>
                                <ul class="st-outline">
                                    @foreach ($section['lessons'] as $lesson)
                                        <li>
                                            @if ($lesson['is_preview'] && ! $viewer['enrolled'])
                                                <a href="{{ route('learning.learn', [$course, $lesson['id']]) }}"><x-td.icon name="eye" /><span>{{ $lesson['title'] }} <span class="st-small">· Free preview</span></span></a>
                                            @elseif ($viewer['enrolled'])
                                                <a href="{{ route('learning.learn', [$course, $lesson['id']]) }}"><x-td.icon :name="in_array($lesson['id'], $viewer['progress']['completed_lesson_ids'] ?? [], true) ? 'circle-check' : 'circle-dashed'" /><span>{{ $lesson['title'] }}</span></a>
                                            @else
                                                <span class="st-outline__locked"><x-td.icon name="lock" /><span>{{ $lesson['title'] }} <span class="st-small">· {{ \App\Domain\Learning\Enums\LessonType::from($lesson['type'])->label() }}@if ($lesson['duration_minutes']), {{ $lesson['duration_minutes'] }} min @endif</span></span></span>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            </li>
                        @endforeach
                    </ol>
                </div>
            </x-td.panel>
            @if ($viewer['enrolled'] && ! $viewer['is_owner'] && ($viewer['progress']['completed'] ?? 0) > 0)
                <x-td.panel title="Review this course" id="review">
                    <form method="POST" action="{{ route('learning.courses.reviews.store', $course) }}" class="st-form">
                        @csrf
                        <fieldset><legend>Stars</legend>
                            <div class="st-stars">@foreach ([5, 4, 3, 2, 1] as $stars)<label><input type="radio" name="rating" value="{{ $stars }}" required> {{ $stars }} <x-td.icon name="star" /></label>@endforeach</div>
                        </fieldset>
                        <x-td.field label="What did you think?" for="body" optional><x-td.textarea name="body" rows="3" maxlength="3000" /></x-td.field>
                        <div class="st-form__actions"><x-td.button type="submit">Send the review</x-td.button></div>
                    </form>
                </x-td.panel>
            @endif
        </div>

        <aside class="st-stack st-course__aside" aria-label="Join this course">
            <x-td.panel :title="$viewer['enrolled'] ? 'Your progress' : 'Join'">
                <div class="st-stack">
                    @if ($viewer['enrolled'])
                        <x-td.progress label="Lessons done" :value="$viewer['progress']['completed']" :max="max(1, $viewer['progress']['total'])" :display="$viewer['progress']['completed'].' of '.$viewer['progress']['total'].' lessons complete'" :stage="$ctx['stage_colour']" />
                        <x-td.button variant="primary" size="lg" :href="route('learning.learn', [$course, $viewer['progress']['resume_lesson_id']])" icon-end="arrow-right">{{ $viewer['progress']['completed'] > 0 ? 'Resume' : 'Start' }}</x-td.button>
                        @if ($viewer['newer_version_available'])
                            <form method="POST" action="{{ route('learning.courses.upgrade', $course) }}" class="st-stack st-stack--tight">@csrf
                                <p class="st-small" style="margin:0">The tutor has published an updated version. Your completed lessons carry over where the lesson still exists.</p>
                                <x-td.button type="submit" size="sm">Move to the new version</x-td.button>
                            </form>
                        @endif
                    @elseif ($data['pricing'] === 'free')
                        <p class="st-title" style="margin:0">Free</p>
                        <form method="POST" action="{{ route('learning.courses.enrol', $course) }}">@csrf<x-td.button type="submit" variant="primary" size="lg">Enrol</x-td.button></form>
                    @else
                        <div class="st-total"><span>Price</span><x-td.money :minor="$data['price_minor']" :currency="$data['currency']" /></div>
                        @if ($quote && $quote->couponAccepted && $quote->payableMinor !== $quote->listMinor)
                            <div class="st-total"><span>With {{ $quote->couponCode }}</span><x-td.money :minor="$quote->payableMinor" :currency="$quote->currency" /></div>
                        @endif
                        <div><p class="st-small" style="margin:0 0 var(--td-space-1)">Refund policy</p><p style="margin:0">{{ $data['refund_policy'] }}</p></div>
                        @if ($viewer['has_access'])
                            <form method="POST" action="{{ route('learning.courses.enrol', $course) }}">@csrf<x-td.button type="submit" variant="primary">Enrol</x-td.button></form>
                        @elseif (! $viewer['is_owner'])
                            <form method="POST" action="{{ route('learning.courses.checkout', $course) }}" class="st-form">
                                @csrf
                                <input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                                <x-td.field label="Coupon code" for="coupon" optional><x-td.input name="coupon" maxlength="64" autocomplete="off" /></x-td.field>
                                @if ($quote?->message)<p class="st-small" style="margin:0">{{ $quote->message }}</p>@endif
                                <x-td.button type="submit" variant="primary" size="lg">Buy course</x-td.button>
                                @if ($ctx['minor'])<p class="st-small" style="margin:0">Your parent or guardian approves the purchase before you pay.</p>@endif
                            </form>
                        @endif
                    @endif
                    @if ($data['certificate'])
                        <p class="st-small" style="margin:0">Completing this course earns a Dot.Tutor completion certificate with a public check code. It is not an accredited qualification.</p>
                    @endif
                </div>
            </x-td.panel>
        </aside>
    </div>
</x-student.shell>
