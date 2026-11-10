<x-app-layout>
    <x-slot name="header">{{ $data['course']['title'] }}</x-slot>
    @include('learning.partials.styles')
    @php($lesson = $data['lesson'])
    @php($progress = $data['progress'])
    @php($done = $progress['completed_lesson_ids'] ?? [])
    <div class="ln">
        <p class="row">
            @if (! ($data['draft'] ?? false))
                <a href="{{ route('learning.courses.show', $course) }}">Course page</a>
            @else
                <a href="{{ route('learning.studio.courses.show', $course) }}">Back to the studio</a>
                <span class="tag warn">Draft preview: learners do not see this until you publish</span>
            @endif
            @if ($progress) <span class="muted">{{ $progress['completed'] }} of {{ $progress['total'] }} lessons complete ({{ $progress['percent'] }}%)</span> @endif
            @if ($data['preview_only'] && ! ($data['draft'] ?? false)) <span class="tag">Free preview</span> @endif
        </p>
        @include('learning.partials.flash')
        @if ($data['newer_version_available'])
            <div class="notice">The tutor published an updated version of this course. <form method="POST" action="{{ route('learning.courses.upgrade', $course) }}" style="display:inline">@csrf<button class="btn ghost" type="submit">Move to the new version</button></form></div>
        @endif

        <div class="grid two">
            <article aria-labelledby="lesson-title">
                @if ($lesson)
                    <h1 id="lesson-title">{{ $lesson['title'] }}</h1>
                    @switch($lesson['type'])
                        @case('video')
                            @if ($lesson['media_url'])
                                <video id="lesson-video" controls preload="metadata" style="width:100%;border-radius:8px;background:#000" src="{{ $lesson['media_url'] }}"
                                    data-resume="{{ ($progress && $progress['resume_lesson_id'] === $lesson['id']) ? $progress['resume_position_seconds'] : 0 }}"
                                    @if ($progress) data-position-url="{{ route('learning.lessons.position', [$course, $lesson['id']]) }}" @endif>
                                    Your browser cannot play this video.
                                </video>
                            @endif
                            @break
                        @case('image')
                            @if ($lesson['media_url'])<img src="{{ $lesson['media_url'] }}" alt="{{ $lesson['title'] }}" style="max-width:100%;border-radius:8px">@endif
                            @break
                        @case('document')
                        @case('slides')
                            @if ($lesson['media_url'])<p><a class="btn ghost" href="{{ $lesson['media_url'] }}" target="_blank" rel="noopener">Open the {{ $lesson['type'] === 'slides' ? 'slides' : 'document' }}</a></p>
                            <p class="hint">The link works for {{ config('learning.signed_url_minutes') }} minutes and only for you. Reload the page for a fresh link.</p>@endif
                            @break
                        @case('video_url')
                            <p><a class="btn ghost" href="{{ $lesson['external_url'] }}" target="_blank" rel="noopener noreferrer">Watch the video on its host site</a></p>
                            @break
                        @case('quiz')
                            @if ($lesson['assessment_id'])<p><a class="btn" href="{{ route('learning.assessments.take', $lesson['assessment_id']) }}">Take the quiz</a></p>
                            <p class="hint">This lesson completes when you submit the quiz.</p>@endif
                            @break
                    @endswitch
                    @if ($lesson['body_html'])
                        <div class="prose">{!! $lesson['body_html'] !!}</div>
                    @endif
                    @if (! empty($data['materials']))
                        <h2>Materials</h2>
                        <ul>
                            @foreach ($data['materials'] as $material)
                                <li><a href="{{ $material['url'] }}">{{ $material['title'] }}</a> <span class="muted">({{ $material['permission'] === 'enrolled_view_only' ? 'view in browser' : 'download' }}, {{ number_format($material['size'] / 1024) }} KB)</span></li>
                            @endforeach
                        </ul>
                    @endif
                    @if ($progress && $lesson['type'] !== 'quiz')
                        @if (in_array($lesson['id'], $done, true))
                            <p class="tag ok" style="margin-top:1.5rem">Completed</p>
                        @else
                            <form method="POST" action="{{ route('learning.lessons.complete', [$course, $lesson['id']]) }}" style="margin-top:1.5rem">@csrf<button class="btn" type="submit">Mark as complete</button></form>
                        @endif
                    @endif
                @else
                    <h1 id="lesson-title">{{ $data['course']['title'] }}</h1>
                    <p class="muted">Enrol to open the lessons. Lessons marked "Free preview" open without enrolling.</p>
                @endif
            </article>

            @if ($data['outline'])
                <nav aria-label="Course lessons" class="panel">
                    @foreach ($data['outline'] as $section)
                        <h3 style="margin-top:0">{{ $section['title'] }}</h3>
                        <ul class="plain">
                            @foreach ($section['lessons'] as $item)
                                <li class="row">
                                    @if (! $data['preview_only'] || $item['is_preview'])
                                        <a href="{{ route('learning.learn', [$course, $item['id']]) }}" @if ($lesson && $lesson['id'] === $item['id']) aria-current="page" style="font-weight:700" @endif>{{ $item['title'] }}</a>
                                    @else
                                        <span class="muted">{{ $item['title'] }}</span>
                                    @endif
                                    @if (in_array($item['id'], $done, true)) <span class="tag ok">Done</span> @endif
                                </li>
                            @endforeach
                        </ul>
                    @endforeach
                </nav>
            @endif
        </div>
    </div>
    <script>
        (function () {
            var video = document.getElementById('lesson-video');
            if (!video) { return; }
            var resume = parseInt(video.dataset.resume || '0', 10);
            video.addEventListener('loadedmetadata', function () { if (resume > 0 && resume < video.duration) { video.currentTime = resume; } }, { once: true });
            var url = video.dataset.positionUrl;
            if (!url) { return; }
            var token = document.querySelector('meta[name="csrf-token"]').content;
            video.addEventListener('pause', function () {
                fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token }, body: JSON.stringify({ seconds: Math.floor(video.currentTime) }) });
            });
        })();
    </script>
</x-app-layout>
