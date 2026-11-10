<x-app-layout>
    <x-slot name="header">Course</x-slot>
    @include('learning.partials.styles')
    @php($viewer = $data['viewer'])
    <div class="ln">
        <p><a href="{{ route('learning.courses.index') }}">All courses</a></p>
        <h1>{{ $data['title'] }}</h1>
        <p class="row muted">
            <span>By {{ $data['tutor']['name'] }}</span>
            <span>{{ $data['academic']['subject'] ?? 'Subject not set' }}</span>
            @if ($data['academic']['level']) <span>{{ $data['academic']['level'] }}</span> @endif
            <span>Taught in: {{ $data['language'] }}</span>
            @if ($data['workload_minutes']) <span>About {{ round($data['workload_minutes'] / 60, 1) }} hours</span> @endif
            @if ($data['reviews']['count'] > 0) <span>{{ $data['reviews']['average'] }} / 5 from {{ $data['reviews']['count'] }} enrolled {{ \Illuminate\Support\Str::plural('learner', $data['reviews']['count']) }}</span> @endif
        </p>
        @include('learning.partials.flash')

        <div class="grid two">
            <div>
                <p class="prose">{{ $data['description'] }}</p>
                @if ($data['objectives'])
                    <h2>You will learn to</h2>
                    <ul>@foreach ($data['objectives'] as $objective)<li>{{ $objective }}</li>@endforeach</ul>
                @endif
                @if ($data['prerequisites'])
                    <h2>Before you start</h2>
                    <ul>@foreach ($data['prerequisites'] as $requirement)<li>{{ $requirement }}</li>@endforeach</ul>
                @endif
                <h2>Course content</h2>
                @foreach ($data['outline'] as $section)
                    <h3>{{ $section['title'] }}</h3>
                    <ul class="plain">
                        @foreach ($section['lessons'] as $lesson)
                            <li class="row">
                                <span>{{ $lesson['title'] }}</span>
                                <span class="tag">{{ \App\Domain\Learning\Enums\LessonType::from($lesson['type'])->label() }}</span>
                                @if ($lesson['duration_minutes']) <span class="muted">{{ $lesson['duration_minutes'] }} min</span> @endif
                                @if ($lesson['is_preview'] && ! $viewer['enrolled']) <a href="{{ route('learning.learn', [$course, $lesson['id']]) }}">Free preview</a> @endif
                            </li>
                        @endforeach
                    </ul>
                @endforeach
            </div>
            <aside class="panel first-on-mobile">
                @if ($viewer['enrolled'])
                    <p><strong>You are enrolled.</strong> {{ $viewer['progress']['completed'] }} of {{ $viewer['progress']['total'] }} lessons complete.</p>
                    <a class="btn" href="{{ route('learning.learn', $course) }}">{{ $viewer['progress']['completed'] > 0 ? 'Resume' : 'Start' }}</a>
                    @if ($viewer['newer_version_available'])
                        <form method="POST" action="{{ route('learning.courses.upgrade', $course) }}" style="margin-top:1rem">@csrf
                            <p class="hint">The tutor has published an updated version. Your completed lessons carry over where the lesson still exists.</p>
                            <button class="btn ghost" type="submit">Move to the new version</button>
                        </form>
                    @endif
                @elseif ($data['pricing'] === 'free')
                    <p><strong>Free</strong></p>
                    <form method="POST" action="{{ route('learning.courses.enrol', $course) }}">@csrf<button class="btn" type="submit">Enrol</button></form>
                @else
                    <p><strong>@include('learning.partials.money', ['minor' => $data['price_minor'], 'currency' => $data['currency']])</strong></p>
                    <h3>Refund policy</h3>
                    <p class="muted">{{ $data['refund_policy'] }}</p>
                    @if ($viewer['has_access'])
                        <form method="POST" action="{{ route('learning.courses.enrol', $course) }}">@csrf<button class="btn" type="submit">Enrol</button></form>
                    @elseif (! $viewer['is_owner'])
                        <form method="POST" action="{{ route('learning.courses.checkout', $course) }}">
                            @csrf
                            <input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                            <label for="coupon">Coupon code (optional)</label>
                            <input id="coupon" name="coupon" type="text" maxlength="64">
                            @if ($quote?->message) <p class="hint">{{ $quote->message }}</p> @endif
                            <p style="margin-top:1rem"><button class="btn" type="submit">Buy course</button></p>
                        </form>
                    @endif
                @endif
                @if ($data['certificate'])
                    <p class="hint" style="margin-top:1rem">Completing this course earns a Dot.Tutor completion certificate with a public verification code. It is not an accredited qualification.</p>
                @endif
            </aside>
        </div>
    </div>
</x-app-layout>
