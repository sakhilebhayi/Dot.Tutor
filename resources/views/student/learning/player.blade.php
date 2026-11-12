{{-- /learning/courses/{course}/learn/{lesson?} (learning.learn) and the creator's draft preview: the course player
     (D-1118). From the learning controller: $course, $data (course, lesson, progress, outline, materials, flags).
     Protected media come as short-lived signed URLs from the learning domain; nothing here widens access. --}}
@php
    $lesson = $data['lesson'];
    $progress = $data['progress'];
    $done = $progress['completed_lesson_ids'] ?? [];
    $draft = $data['draft'] ?? false;
    $flat = collect($data['outline'] ?? [])->flatMap(fn ($s) => $s['lessons'])->values();
    $index = $lesson ? $flat->search(fn ($l) => $l['id'] === $lesson['id']) : false;
    $next = $index !== false ? $flat->get($index + 1) : null;
    $canOpen = fn ($item) => ! $data['preview_only'] || $item['is_preview'];
@endphp
<x-student.shell :title="$lesson['title'] ?? $data['course']['title']">
    <div class="st-inline st-inline--loose">
        @if (! $draft)
            <x-td.button variant="quiet" size="sm" icon="arrow-left" :href="route('learning.courses.show', $course)">{{ $data['course']['title'] }}</x-td.button>
        @else
            <x-td.button variant="quiet" size="sm" icon="arrow-left" :href="route('learning.studio.courses.show', $course)">Back to the studio</x-td.button>
            <span class="td-badge td-badge--review"><x-td.icon name="eye" />Draft preview: learners do not see this until you publish</span>
        @endif
        @if ($data['preview_only'] && ! $draft)<span class="td-badge td-badge--peer"><x-td.icon name="eye" />Free preview</span>@endif
    </div>

    @if ($progress)
        <x-td.progress label="Your progress" :value="$progress['completed']" :max="max(1, $progress['total'])" :display="$progress['completed'].' of '.$progress['total'].' lessons complete'" :stage="$ctx['stage_colour']" />
    @endif
    @if ($errors->any())<x-td.notice tone="danger" title="That did not go through">{{ $errors->first() }}</x-td.notice>@endif
    @if ($data['newer_version_available'])
        <x-td.notice tone="info" title="An updated version is available">
            <form method="POST" action="{{ route('learning.courses.upgrade', $course) }}" class="st-inline">@csrf<span>Your completed lessons carry over where the lesson still exists.</span><x-td.button type="submit" size="sm">Move to the new version</x-td.button></form>
        </x-td.notice>
    @endif

    <div class="st-player">
        <article class="st-stack" aria-labelledby="lesson-title">
            @if ($lesson)
                <h1 class="td-page-head__title" id="lesson-title" style="margin:0">{{ $lesson['title'] }}</h1>
                @switch($lesson['type'])
                    @case('video')
                        @if ($lesson['media_url'])
                            <video id="lesson-video" controls preload="metadata" playsinline src="{{ $lesson['media_url'] }}"
                                data-resume="{{ ($progress && $progress['resume_lesson_id'] === $lesson['id']) ? $progress['resume_position_seconds'] : 0 }}"
                                @if ($progress) data-position-url="{{ route('learning.lessons.position', [$course, $lesson['id']]) }}" @endif>
                                Your browser cannot play this video. <a href="{{ $lesson['media_url'] }}">Open it directly</a>.
                            </video>
                            <p class="st-small" style="margin:0">The video loads only when you press play, to save data. It picks up where you stopped.</p>
                        @endif
                        @break
                    @case('image')
                        @if ($lesson['media_url'])<img src="{{ $lesson['media_url'] }}" alt="{{ $lesson['title'] }}" loading="lazy">@endif
                        @break
                    @case('document')
                    @case('slides')
                        @if ($lesson['media_url'])
                            <div><x-td.button icon="external-link" :href="$lesson['media_url']" target="_blank" rel="noopener">Open the {{ $lesson['type'] === 'slides' ? 'slides' : 'document' }}</x-td.button></div>
                            <p class="st-small" style="margin:0">The link works for {{ config('learning.signed_url_minutes') }} minutes and only for you. Reload the page for a fresh link.</p>
                        @endif
                        @break
                    @case('video_url')
                        <div><x-td.button icon="external-link" :href="$lesson['external_url']" target="_blank" rel="noopener noreferrer">Watch the video on its own site</x-td.button></div>
                        <p class="st-small" style="margin:0">This video is hosted elsewhere and uses that site's data.</p>
                        @break
                    @case('quiz')
                        @if ($lesson['assessment_id'])
                            <div><x-td.button variant="primary" icon="list-checks" :href="route('learning.assessments.take', $lesson['assessment_id'])">Take the quiz</x-td.button></div>
                            <p class="st-small" style="margin:0">This lesson completes when you submit the quiz.</p>
                        @endif
                        @break
                @endswitch
                @if ($lesson['body_html'])
                    <div class="st-prose">{!! $lesson['body_html'] !!}</div>
                @endif
                @if (! empty($data['materials']))
                    <x-td.panel title="Materials" flush>
                        <ul class="st-list">
                            @foreach ($data['materials'] as $material)
                                <li class="st-row">
                                    <div><p class="st-row__title"><a href="{{ $material['url'] }}">{{ $material['title'] }}</a></p>
                                        <p class="st-row__meta">{{ $material['permission'] === 'enrolled_view_only' ? 'View in the browser' : 'Download' }}, {{ number_format($material['size'] / 1024) }} KB</p></div>
                                    <div class="st-row__end"><x-td.button size="sm" :icon="$material['permission'] === 'enrolled_view_only' ? 'eye' : 'download'" :href="$material['url']">{{ $material['permission'] === 'enrolled_view_only' ? 'View' : 'Download' }}</x-td.button></div>
                                </li>
                            @endforeach
                        </ul>
                    </x-td.panel>
                @endif
                <div class="st-inline st-inline--loose">
                    @if ($progress && $lesson['type'] !== 'quiz')
                        @if (in_array($lesson['id'], $done, true))
                            <span class="td-badge td-badge--verified"><x-td.icon name="circle-check" />Completed</span>
                        @else
                            <form method="POST" action="{{ route('learning.lessons.complete', [$course, $lesson['id']]) }}">@csrf<x-td.button type="submit" variant="primary" icon="check">Mark as complete</x-td.button></form>
                        @endif
                    @endif
                    @if ($next && $canOpen($next))
                        <x-td.button :href="route('learning.learn', [$course, $next['id']])" icon-end="arrow-right">Next: {{ $next['title'] }}</x-td.button>
                    @endif
                </div>
            @else
                <h1 class="td-page-head__title" id="lesson-title" style="margin:0">{{ $data['course']['title'] }}</h1>
                <p class="st-muted" style="margin:0">Enrol to open the lessons. Lessons marked "Free preview" open without enrolling.</p>
            @endif
        </article>

        @if ($data['outline'])
            <nav aria-label="Course lessons" class="td-panel">
                <div class="td-panel__body">
                    @foreach ($data['outline'] as $section)
                        <p class="st-outline__section">{{ $section['title'] }}</p>
                        <ul class="st-outline">
                            {{-- One line per lesson: a 300-lesson course printed 170 KB of indentation (R-47 D-1611). --}}
                            @foreach ($section['lessons'] as $item)
                                @php($isDone = in_array($item['id'], $done, true))
                                <li>@if ($canOpen($item))<a href="{{ route('learning.learn', [$course, $item['id']]) }}" @if ($lesson && $lesson['id'] === $item['id']) aria-current="page" @endif><x-td.icon :name="$isDone ? 'circle-check' : 'circle-dashed'" /><span>{{ $item['title'] }}@if ($isDone)<span class="td-sr"> (done)</span>@endif</span></a>@else<span class="st-outline__locked"><x-td.icon name="lock" /><span>{{ $item['title'] }}</span></span>@endif</li>
                            @endforeach
                        </ul>
                    @endforeach
                </div>
            </nav>
        @endif
    </div>
</x-student.shell>
