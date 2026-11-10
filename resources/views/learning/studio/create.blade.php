<x-app-layout>
    <x-slot name="header">Course studio</x-slot>
    @include('learning.partials.styles')
    <div class="ln">
        <h1>Your courses</h1>
        <p class="muted">Start a draft, add sections and lessons, set the price, then publish. Nothing is visible to learners until you publish.</p>
        @include('learning.partials.flash')
        @unless ($canPublish)
            <div class="notice">You can build drafts now. Publishing needs an approved tutor profile: <a href="{{ route('tutor.profile.edit') }}">finish your tutor profile</a>.</div>
        @endunless

        <div class="grid two">
            <section aria-labelledby="mine">
                <h2 id="mine">Drafts and published courses</h2>
                @forelse ($courses as $course)
                    <ul class="plain">
                        <li class="row">
                            <a href="{{ route('learning.studio.courses.show', $course) }}"><strong>{{ $course->title }}</strong></a>
                            <span class="tag {{ $course->status->value === 'published' ? 'ok' : '' }}">{{ ucfirst($course->status->value) }}</span>
                            <span class="muted">
                                @if ($course->pricing === 'paid') @include('learning.partials.money', ['minor' => $course->price_minor, 'currency' => $course->currency])
                                @elseif ($course->pricing === 'free') Free
                                @else Price not set @endif
                            </span>
                        </li>
                    </ul>
                @empty
                    <p class="muted">No courses yet. Your first draft starts with a title; everything else can come later.</p>
                @endforelse
            </section>

            <section class="panel" aria-labelledby="new">
                <h2 id="new" style="margin-top:0">Start a course</h2>
                <form method="POST" action="{{ route('learning.studio.courses.store') }}">
                    @csrf
                    <label for="title">Course title</label>
                    <input id="title" name="title" type="text" required maxlength="160" value="{{ old('title') }}" placeholder="Grade 11 Physical Sciences: Newton's laws">
                    <label for="subject_id">Subject</label>
                    <select id="subject_id" name="subject_id">
                        <option value="">Choose later</option>
                        @foreach ($subjects as $subject)
                            <option value="{{ $subject->id }}" @selected(old('subject_id') == $subject->id)>{{ $subject->name }}</option>
                        @endforeach
                    </select>
                    <label for="original_locale">Teaching language</label>
                    <input id="original_locale" name="original_locale" type="text" value="{{ old('original_locale', 'en') }}" maxlength="20" aria-describedby="locale-hint">
                    <p class="hint" id="locale-hint">Language code: en, zu, xh, af, st, tn, nso, ts, ss, ve, nr, pt, fr, sw.</p>
                    <p style="margin-top:1rem"><button class="btn" type="submit">Create draft</button></p>
                </form>
            </section>
        </div>
    </div>
</x-app-layout>
