<x-app-layout>
    <x-slot name="header">Course studio</x-slot>
    @include('learning.partials.styles')
    @php($canPublish = $draft['publish_problems'] === [])
    <div class="ln">
        <p><a href="{{ route('learning.studio.courses.index') }}">All your courses</a></p>
        <h1>{{ $draft['title'] }}</h1>
        <p class="row">
            <span class="tag {{ $draft['status'] === 'published' ? 'ok' : '' }}">{{ ucfirst($draft['status']) }}</span>
            @if ($draft['versions'] !== [])
                <span class="muted">Published version {{ last($draft['versions'])['number'] }}</span>
                @if ($draft['has_unpublished_changes']) <span class="tag warn">Unpublished changes</span> @endif
                <a href="{{ route('learning.courses.show', $course) }}">Learner view</a>
            @endif
        </p>
        @include('learning.partials.flash')

        <div class="grid two">
            <div>
                <section aria-labelledby="structure">
                    <h2 id="structure">Sections and lessons</h2>
                    @forelse ($draft['sections'] as $section)
                        <fieldset>
                            <legend>{{ $section['title'] }}</legend>
                            <ul class="plain">
                                @forelse ($section['lessons'] as $lesson)
                                    <li>
                                        <div class="row">
                                            <strong>{{ $lesson['title'] }}</strong>
                                            <span class="tag">{{ \App\Domain\Learning\Enums\LessonType::from($lesson['type'])->label() }}</span>
                                            @if ($lesson['is_preview']) <span class="tag ok">Free preview</span> @endif
                                            <a href="{{ route('learning.studio.lessons.preview', $lesson['id']) }}">Preview</a>
                                        </div>
                                        <details>
                                            <summary>Edit</summary>
                                            <form method="POST" action="{{ route('learning.studio.lessons.update', $lesson['id']) }}" enctype="multipart/form-data">
                                                @csrf
                                                <label for="l{{ $lesson['id'] }}-title">Title</label>
                                                <input id="l{{ $lesson['id'] }}-title" name="title" type="text" value="{{ $lesson['title'] }}" maxlength="160">
                                                @if (in_array($lesson['type'], ['text', 'practice'], true))
                                                    <label for="l{{ $lesson['id'] }}-body">Content (Markdown)</label>
                                                    <textarea id="l{{ $lesson['id'] }}-body" name="body">{{ $lesson['body'] }}</textarea>
                                                @elseif ($lesson['type'] === 'video_url')
                                                    <label for="l{{ $lesson['id'] }}-url">Video link</label>
                                                    <input id="l{{ $lesson['id'] }}-url" name="external_url" type="url" value="{{ $lesson['external_url'] }}">
                                                @elseif ($lesson['type'] !== 'quiz')
                                                    <label for="l{{ $lesson['id'] }}-file">Replace file</label>
                                                    <input id="l{{ $lesson['id'] }}-file" name="file" type="file">
                                                    <p class="hint">Current: {{ $lesson['media_original_name'] ?? 'none uploaded' }}</p>
                                                @endif
                                                <input type="hidden" name="is_preview" value="0">
                                                <label><input type="checkbox" name="is_preview" value="1" @checked($lesson['is_preview'])> Free preview for anyone</label>
                                                <p class="row"><button class="btn" type="submit">Save lesson</button></p>
                                            </form>
                                            <form method="POST" action="{{ route('learning.studio.lessons.destroy', $lesson['id']) }}">
                                                @csrf @method('DELETE')
                                                <button class="btn danger" type="submit">Remove lesson from draft</button>
                                            </form>
                                        </details>
                                    </li>
                                @empty
                                    <li class="muted">No lessons in this section yet.</li>
                                @endforelse
                            </ul>
                            <details>
                                <summary>Add a lesson to {{ $section['title'] }}</summary>
                                <form method="POST" action="{{ route('learning.studio.lessons.store', $section['id']) }}" enctype="multipart/form-data">
                                    @csrf
                                    <label for="s{{ $section['id'] }}-title">Lesson title</label>
                                    <input id="s{{ $section['id'] }}-title" name="title" type="text" required maxlength="160">
                                    <label for="s{{ $section['id'] }}-type">Type</label>
                                    <select id="s{{ $section['id'] }}-type" name="type">
                                        @foreach (\App\Domain\Learning\Enums\LessonType::cases() as $type)
                                            <option value="{{ $type->value }}">{{ $type->label() }}</option>
                                        @endforeach
                                    </select>
                                    <label for="s{{ $section['id'] }}-body">Content for reading or practice (Markdown)</label>
                                    <textarea id="s{{ $section['id'] }}-body" name="body"></textarea>
                                    <label for="s{{ $section['id'] }}-url">External video link (YouTube or Vimeo, https)</label>
                                    <input id="s{{ $section['id'] }}-url" name="external_url" type="url">
                                    <p class="hint">External videos play from their host and are not protected by Dot.Tutor's access control. Upload the video to protect it.</p>
                                    <label for="s{{ $section['id'] }}-file">File for video, document, image or slides</label>
                                    <input id="s{{ $section['id'] }}-file" name="file" type="file">
                                    <p class="hint">PDF, Word, PowerPoint, Excel, OpenDocument, JPEG, PNG, WebP, MP4 or WebM. Images are re-saved to remove hidden metadata.</p>
                                    @if ($quizzes->isNotEmpty())
                                        <label for="s{{ $section['id'] }}-quiz">Quiz (for a quiz lesson)</label>
                                        <select id="s{{ $section['id'] }}-quiz" name="assessment_id">
                                            <option value="">None</option>
                                            @foreach ($quizzes as $quiz)
                                                <option value="{{ $quiz->id }}">{{ $quiz->title }}</option>
                                            @endforeach
                                        </select>
                                    @endif
                                    <label for="s{{ $section['id'] }}-minutes">Estimated minutes</label>
                                    <input id="s{{ $section['id'] }}-minutes" name="duration_minutes" type="number" min="1" max="10000">
                                    <p style="margin-top:1rem"><button class="btn" type="submit">Add lesson</button></p>
                                </form>
                            </details>
                        </fieldset>
                    @empty
                        <p class="muted">A course needs at least one section with one lesson before it can publish.</p>
                    @endforelse
                    <form method="POST" action="{{ route('learning.studio.sections.store', $course) }}" class="row">
                        @csrf
                        <label for="section-title" style="margin:0">New section</label>
                        <input id="section-title" name="title" type="text" required maxlength="160" style="max-width:22rem" placeholder="Forces and motion">
                        <button class="btn ghost" type="submit">Add section</button>
                    </form>
                </section>

                <section aria-labelledby="materials">
                    <h2 id="materials">Downloadable materials</h2>
                    @if ($draft['materials'] === [])
                        <p class="muted">No materials yet.</p>
                    @else
                        <ul class="plain">
                            @foreach ($draft['materials'] as $material)
                                <li class="row">
                                    <span>{{ $material['title'] }}</span>
                                    <span class="tag">{{ ['enrolled_download' => 'Enrolled learners download', 'enrolled_view_only' => 'Enrolled learners view only', 'public_preview' => 'Anyone signed in'][$material['permission']] }}</span>
                                    <form method="POST" action="{{ route('learning.studio.materials.destroy', $material['id']) }}">@csrf @method('DELETE')<button class="btn danger" type="submit">Remove</button></form>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    <form method="POST" action="{{ route('learning.studio.materials.store', $course) }}" enctype="multipart/form-data">
                        @csrf
                        <label for="m-title">Title</label>
                        <input id="m-title" name="title" type="text" required maxlength="160">
                        <label for="m-permission">Who can get it</label>
                        <select id="m-permission" name="permission">
                            <option value="enrolled_download">Enrolled learners can download</option>
                            <option value="enrolled_view_only">Enrolled learners can view in the browser</option>
                            <option value="public_preview">Anyone signed in (a free sample)</option>
                        </select>
                        <p class="hint">"View only" opens the file in the browser instead of offering a download; it cannot stop someone saving what they can see.</p>
                        <label for="m-file">File</label>
                        <input id="m-file" name="file" type="file" required>
                        <p style="margin-top:1rem"><button class="btn ghost" type="submit">Upload material</button></p>
                    </form>
                </section>
            </div>

            <aside>
                <section class="panel" aria-labelledby="publish">
                    <h2 id="publish" style="margin-top:0">Publish</h2>
                    @if ($canPublish)
                        <p>Ready. Learners already enrolled stay on their version until they choose to move to the new one.</p>
                    @else
                        <p>Before this course can publish:</p>
                        <ul>
                            @foreach ($draft['publish_problems'] as $problem)
                                <li>{{ $problem }}</li>
                            @endforeach
                        </ul>
                    @endif
                    <form method="POST" action="{{ route('learning.studio.courses.publish', $course) }}">
                        @csrf
                        <button class="btn" type="submit" @disabled(! $canPublish)>{{ $draft['versions'] === [] ? 'Publish course' : 'Publish update' }}</button>
                    </form>
                </section>

                <section class="panel" aria-labelledby="details" style="margin-top:1.5rem">
                    <h2 id="details" style="margin-top:0">Details and price</h2>
                    <form method="POST" action="{{ route('learning.studio.courses.update', $course) }}">
                        @csrf @method('PUT')
                        <label for="d-title">Title</label>
                        <input id="d-title" name="title" type="text" value="{{ old('title', $draft['title']) }}" maxlength="160" required>
                        <label for="d-description">Description</label>
                        <textarea id="d-description" name="description" maxlength="5000">{{ old('description', $draft['description']) }}</textarea>
                        <label for="d-subject">Subject</label>
                        <select id="d-subject" name="subject_id">
                            <option value="">Not chosen</option>
                            @foreach ($subjects as $subject)
                                <option value="{{ $subject->id }}" @selected($draft['subject_id'] == $subject->id)>{{ $subject->name }}</option>
                            @endforeach
                        </select>
                        <p class="hint">Curriculum and level arrive with the academic structure.</p>
                        <label for="d-locale">Teaching language code</label>
                        <input id="d-locale" name="original_locale" type="text" value="{{ $draft['original_locale'] }}" maxlength="20">
                        <fieldset style="margin-top:1rem">
                            <legend>Learning objectives</legend>
                            @foreach (array_pad($draft['objectives'], max(3, count($draft['objectives']) + 1), '') as $i => $objective)
                                <label for="obj{{ $i }}" class="sr-only">Objective {{ $i + 1 }}</label>
                                <input id="obj{{ $i }}" name="objectives[]" type="text" value="{{ $objective }}" maxlength="300" style="margin-top:.4rem">
                            @endforeach
                        </fieldset>
                        <fieldset>
                            <legend>Requirements</legend>
                            @foreach (array_pad($draft['prerequisites'], max(2, count($draft['prerequisites']) + 1), '') as $i => $requirement)
                                <label for="pre{{ $i }}" class="sr-only">Requirement {{ $i + 1 }}</label>
                                <input id="pre{{ $i }}" name="prerequisites[]" type="text" value="{{ $requirement }}" maxlength="300" style="margin-top:.4rem">
                            @endforeach
                        </fieldset>
                        <label for="d-workload">Estimated workload (minutes)</label>
                        <input id="d-workload" name="workload_minutes" type="number" min="1" value="{{ $draft['workload_minutes'] }}">
                        <label for="d-pricing">Price</label>
                        <select id="d-pricing" name="pricing">
                            <option value="" @selected($draft['pricing'] === null)>Not chosen</option>
                            <option value="free" @selected($draft['pricing'] === 'free')>Free</option>
                            <option value="paid" @selected($draft['pricing'] === 'paid')>Paid</option>
                        </select>
                        <label for="d-price">Price in cents ({{ $draft['currency'] }})</label>
                        <input id="d-price" name="price_minor" type="number" min="0" value="{{ $draft['price_minor'] }}" aria-describedby="price-hint">
                        <p class="hint" id="price-hint">24900 means {{ $draft['currency'] }} 249.00. Coupons and promotions are applied at checkout.</p>
                        <label for="d-refund">Refund policy shown before purchase</label>
                        <textarea id="d-refund" name="refund_policy" maxlength="3000">{{ $draft['refund_policy'] }}</textarea>
                        <label for="d-rule">Completion certificate</label>
                        <select id="d-rule" name="completion_rule">
                            <option value="" @selected($draft['completion_rule'] === null)>No certificate</option>
                            <option value="all_lessons" @selected($draft['completion_rule'] === 'all_lessons')>When every lesson is complete</option>
                            <option value="all_lessons_and_quizzes" @selected($draft['completion_rule'] === 'all_lessons_and_quizzes')>When every lesson is complete and every quiz passed</option>
                        </select>
                        <p style="margin-top:1rem"><button class="btn" type="submit">Save details</button></p>
                    </form>
                </section>
            </aside>
        </div>
    </div>
</x-app-layout>
