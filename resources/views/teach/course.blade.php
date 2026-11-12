@php
    $tabLabels = ['basics' => 'Basics', 'outcomes' => 'Outcomes', 'structure' => 'Lessons', 'quizzes' => 'Quizzes', 'price' => 'Price', 'publish' => 'Check and publish', 'learners' => 'Learners'];
    $tabs = collect($tabLabels)->map(fn ($label, $key) => ['label' => $label, 'href' => route('teach.courses.show', [$course, 'tab' => $key]), 'current' => $tab === $key, 'count' => $key === 'publish' && $problems !== [] ? count($problems) : null])->values()->all();
    $statusLabel = ['draft' => 'Draft, not published', 'published' => 'Published', 'archived' => 'Archived'][$course->status->value];
    $languageOptions = collect($languages)->map(fn ($l) => ['value' => $l['code'], 'label' => $l['endonym'] === $l['name'] ? $l['name'] : $l['endonym'].' ('.$l['name'].')'])->all();
    $m = fn (int $minor) => \App\Domain\Teach\Support\TutorContext::money($minor, $course->currency);
    $lessonCount = collect($draft['sections'])->sum(fn ($s) => count($s['lessons']));
    $typeLabels = collect($lessonTypes)->mapWithKeys(fn ($t) => [$t->value => $t->label()])->all();
    $permissionLabels = ['enrolled_download' => 'Enrolled learners can download', 'enrolled_view_only' => 'Enrolled learners can view in the browser only', 'public_preview' => 'Anyone signed in (a free sample)'];
    $maxKb = (int) config('learning.max_upload_kb');
    $maxVideoKb = (int) config('learning.max_video_kb');
@endphp
<x-layouts::workspace :title="$course->title">
    <x-td.page-head :title="$course->title" :lede="$statusLabel.'. '.$lessonCount.' '.\Illuminate\Support\Str::plural('lesson', $lessonCount).' in '.count($draft['sections']).' '.\Illuminate\Support\Str::plural('section', count($draft['sections'])).($unpublished ? '. You have changes that are not published yet.' : '.')">
        <x-slot:actions>
            <x-td.button href="{{ route('teach.courses') }}" icon="arrow-left">Courses</x-td.button>
            @if ($course->status->value === 'published')
                <x-td.button href="{{ route('learning.courses.show', $course) }}" icon="external-link">Course page</x-td.button>
            @endif
        </x-slot:actions>
    </x-td.page-head>

    {{-- The learning studio routes answer back here with a "status" message or validation errors. --}}
    @if (session('status'))
        <x-td.notice tone="success" class="mb-4" role="status">{{ session('status') }}</x-td.notice>
    @endif
    @if ($errors->any())
        <x-td.notice tone="danger" title="That was not saved" class="mb-4">
            <ul class="m-0 pl-5">@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
        </x-td.notice>
    @endif

    <x-td.tabs label="Course steps" :items="$tabs" class="mb-6" />

    @switch($tab)
        @case('basics')
            <x-td.panel title="Basics">
                <form method="POST" action="{{ route('teach.courses.details', $course) }}" class="grid max-w-2xl gap-5">
                    @csrf @method('PUT')
                    <input type="hidden" name="tab" value="basics"><input type="hidden" name="next" value="outcomes">
                    <x-td.field label="Course title" for="title"><x-td.input name="title" :value="$course->title" maxlength="160" required /></x-td.field>
                    <x-td.field label="Description" for="description" hint="What learners will be able to do, who it is for, and how it is taught. At least 30 characters to publish.">
                        <x-td.textarea name="description" :value="$course->description" rows="6" :hint="true" />
                    </x-td.field>
                    <x-td.field label="Subject" for="subject_id"><x-td.select name="subject_id" :options="$subjects" :value="$course->subject_id" placeholder="Choose a subject" /></x-td.field>
                    <x-td.field label="Level" for="level_id" optional hint="{{ $draft['academic']['curriculum'] ? 'Curriculum: '.$draft['academic']['curriculum'].'. ' : '' }}The curriculum comes with the level.">
                        <x-td.select name="level_id" :options="$levels" :value="$course->level_id" placeholder="Any level" :hint="true" />
                    </x-td.field>
                    <x-td.field label="Language it is taught in" for="original_locale"><x-td.select name="original_locale" :options="$languageOptions" :value="$course->original_locale" /></x-td.field>
                    <div><x-td.button type="submit" variant="primary">Save and continue</x-td.button></div>
                </form>
            </x-td.panel>
            @break

        @case('outcomes')
            @php $objectives = array_pad($draft['objectives'], max(4, count($draft['objectives']) + 1), ''); $prereq = array_pad($draft['prerequisites'], max(2, count($draft['prerequisites']) + 1), ''); @endphp
            <x-td.panel title="What learners will get">
                <form method="POST" action="{{ route('teach.courses.details', $course) }}" class="grid max-w-2xl gap-5">
                    @csrf @method('PUT')
                    <input type="hidden" name="tab" value="outcomes"><input type="hidden" name="next" value="structure">
                    <fieldset class="grid gap-3">
                        <legend class="td-label">Learning objectives</legend>
                        <p class="m-0 text-sm text-td-ink-2">Start each with a verb a learner can do: "Solve quadratic equations by factorising".</p>
                        @foreach ($objectives as $i => $o)
                            <x-td.input name="objectives[]" id="objective-{{ $i }}" :value="$o" maxlength="300" aria-label="Objective {{ $i + 1 }}" />
                        @endforeach
                    </fieldset>
                    <fieldset class="grid gap-3">
                        <legend class="td-label">What learners need first <span class="td-label__optional">(optional)</span></legend>
                        @foreach ($prereq as $i => $p)
                            <x-td.input name="prerequisites[]" id="prereq-{{ $i }}" :value="$p" maxlength="300" aria-label="Requirement {{ $i + 1 }}" />
                        @endforeach
                    </fieldset>
                    <x-td.field label="Estimated workload (minutes)" for="workload_minutes" optional hint="Total time a learner needs, shown on the course page.">
                        <x-td.input name="workload_minutes" type="number" min="1" max="100000" :value="$course->workload_minutes" :hint="true" />
                    </x-td.field>
                    <div><x-td.button type="submit" variant="primary">Save and continue</x-td.button></div>
                </form>
            </x-td.panel>
            @break

        @case('structure')
            <div class="grid gap-6">
                @forelse ($draft['sections'] as $si => $section)
                    <x-td.panel :title="'Section '.($si + 1).': '.$section['title']">
                        <x-slot:actions>
                            <form method="POST" action="{{ route('learning.studio.sections.destroy', $section['id']) }}">@csrf @method('DELETE')<x-td.button type="submit" variant="quiet" size="sm" icon="trash-2">Remove section<span class="td-sr"> {{ $section['title'] }}</span></x-td.button></form>
                        </x-slot:actions>
                        @if ($section['lessons'] === [])
                            <p class="mt-0 text-td-ink-2">No lessons in this section yet. A section needs at least one lesson to publish.</p>
                        @else
                            <ol class="m-0 grid list-none gap-0 p-0">
                                @foreach ($section['lessons'] as $li => $lesson)
                                    @php $needsFile = in_array($lesson['type'], ['video', 'document', 'image', 'slides'], true); @endphp
                                    <li class="grid gap-2 border-t border-td-line py-3 first:border-t-0">
                                        <div class="flex flex-wrap items-baseline justify-between gap-3">
                                            <p class="m-0"><strong>{{ $li + 1 }}. {{ $lesson['title'] }}</strong> <span class="text-td-ink-2">{{ $typeLabels[$lesson['type']] ?? $lesson['type'] }}{{ $lesson['duration_minutes'] ? ', '.$lesson['duration_minutes'].' min' : '' }}{{ $lesson['is_preview'] ? ', free preview' : '' }}</span></p>
                                            <div class="flex flex-wrap gap-2">
                                                <x-td.button href="{{ route('learning.studio.lessons.preview', $lesson['id']) }}" size="sm" icon="eye">Preview as a learner<span class="td-sr"> {{ $lesson['title'] }}</span></x-td.button>
                                                <form method="POST" action="{{ route('learning.studio.lessons.destroy', $lesson['id']) }}">@csrf @method('DELETE')<x-td.button type="submit" variant="quiet" size="sm" icon="trash-2">Remove<span class="td-sr"> {{ $lesson['title'] }}</span></x-td.button></form>
                                            </div>
                                        </div>
                                        <p class="m-0 text-sm text-td-ink-2">
                                            @if ($needsFile)
                                                {{ $lesson['has_media'] ? 'File: '.$lesson['media_original_name'].' ('.number_format(($lesson['media_size'] ?? 0) / 1024).' KB)' : 'No file yet: upload one to publish.' }}
                                            @elseif ($lesson['type'] === 'video_url')
                                                {{ $lesson['external_url'] ?: 'No link yet.' }} External links are not protected: anyone with the link can watch.
                                            @elseif ($lesson['type'] === 'quiz')
                                                {{ $lesson['assessment_id'] ? 'Quiz: '.($quizzes->firstWhere('id', $lesson['assessment_id'])?->title ?? 'chosen') : 'No quiz chosen yet.' }}
                                            @else
                                                {{ \Illuminate\Support\Str::limit((string) $lesson['body'], 140) ?: 'No text yet.' }}
                                            @endif
                                        </p>
                                    </li>
                                @endforeach
                            </ol>
                        @endif

                        <details class="mt-4 border-t border-td-line pt-4">
                            <summary class="td-btn td-btn--sm cursor-pointer">Add a lesson to this section</summary>
                            <form method="POST" action="{{ route('learning.studio.lessons.store', $section['id']) }}" enctype="multipart/form-data" class="mt-4 grid max-w-2xl gap-4" data-teach-upload>
                                @csrf
                                <x-td.field label="Lesson title" for="lesson-{{ $section['id'] }}-title"><x-td.input name="title" id="lesson-{{ $section['id'] }}-title" maxlength="160" required /></x-td.field>
                                <x-td.field label="Kind of lesson" for="lesson-{{ $section['id'] }}-type" hint="Reading and practice use the text box; video, document, image and slides take a file; an external video takes a YouTube or Vimeo link; a quiz uses one of this course's quizzes.">
                                    <x-td.select name="type" id="lesson-{{ $section['id'] }}-type" :options="$typeLabels" :hint="true" />
                                </x-td.field>
                                <x-td.field label="Text" for="lesson-{{ $section['id'] }}-body" optional hint="Plain text with Markdown headings and lists; HTML is removed.">
                                    <x-td.textarea name="body" id="lesson-{{ $section['id'] }}-body" rows="5" :hint="true" />
                                </x-td.field>
                                <x-td.file-picker name="file" id="lesson-{{ $section['id'] }}-file" label="File (video, document, image or slides)" :max-kb="$maxVideoKb" hint="Videos up to {{ (int) round($maxVideoKb / 1024) }} MB (MP4 or WebM); other files up to {{ (int) round($maxKb / 1024) }} MB. PDF and Office files without macros. No virus scanner runs on this server." />
                                <x-td.field label="External video link" for="lesson-{{ $section['id'] }}-url" optional><x-td.input name="external_url" id="lesson-{{ $section['id'] }}-url" type="url" placeholder="https://" /></x-td.field>
                                @if ($quizzes->isNotEmpty())
                                    <x-td.field label="Quiz" for="lesson-{{ $section['id'] }}-quiz" optional><x-td.select name="assessment_id" id="lesson-{{ $section['id'] }}-quiz" :options="$quizzes->pluck('title', 'id')->all()" placeholder="Choose a quiz" /></x-td.field>
                                @endif
                                <div class="grid gap-4 sm:grid-cols-2">
                                    <x-td.field label="Minutes" for="lesson-{{ $section['id'] }}-minutes" optional><x-td.input name="duration_minutes" id="lesson-{{ $section['id'] }}-minutes" type="number" min="1" max="10000" /></x-td.field>
                                    <div class="self-end"><input type="hidden" name="is_preview" value="0"><x-td.check name="is_preview" id="lesson-{{ $section['id'] }}-preview">Free preview for anyone</x-td.check></div>
                                </div>
                                <div><x-td.button type="submit" variant="primary">Add lesson</x-td.button></div>
                            </form>
                        </details>
                    </x-td.panel>
                @empty
                    <x-td.state kind="empty" title="Start with a section">Sections group lessons: "Week 1: Forces", "Past paper practice". Add one, then its lessons.</x-td.state>
                @endforelse

                <x-td.panel title="Add a section">
                    <form method="POST" action="{{ route('learning.studio.sections.store', $course) }}" class="flex max-w-2xl flex-wrap items-end gap-3">
                        @csrf
                        <x-td.field label="Section title" for="section-title" class="grow"><x-td.input name="title" id="section-title" maxlength="160" required /></x-td.field>
                        <x-td.button type="submit" variant="primary" icon="plus">Add section</x-td.button>
                    </form>
                </x-td.panel>

                <x-td.panel title="Downloadable materials">
                    @if ($draft['materials'] === [])
                        <p class="mt-0 text-td-ink-2">Worksheets, memos and notes that go with the course. You choose who may open each one.</p>
                    @else
                        <ul class="m-0 grid list-none gap-2 p-0">
                            @foreach ($draft['materials'] as $mat)
                                <li class="flex flex-wrap items-center justify-between gap-2">
                                    <span>{{ $mat['title'] }} <span class="text-td-ink-2">{{ $permissionLabels[$mat['permission']] ?? $mat['permission'] }}</span></span>
                                    <form method="POST" action="{{ route('learning.studio.materials.destroy', $mat['id']) }}">@csrf @method('DELETE')<x-td.button type="submit" variant="quiet" size="sm">Remove<span class="td-sr"> {{ $mat['title'] }}</span></x-td.button></form>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    <form method="POST" action="{{ route('learning.studio.materials.store', $course) }}" enctype="multipart/form-data" class="mt-4 grid max-w-2xl gap-4 border-t border-td-line pt-4" data-teach-upload>
                        @csrf
                        <x-td.field label="Title" for="material-title"><x-td.input name="title" id="material-title" maxlength="160" required /></x-td.field>
                        <x-td.field label="Who may open it" for="material-permission"><x-td.select name="permission" id="material-permission" :options="$permissionLabels" /></x-td.field>
                        <x-td.file-picker name="file" id="material-file" label="Choose the file" :max-kb="$maxKb" />
                        <div><x-td.button type="submit">Upload material</x-td.button></div>
                    </form>
                    <p class="mb-0 mt-3 text-sm text-td-ink-2">"View in the browser only" makes downloading harder, not impossible: it is not copy protection.</p>
                </x-td.panel>
            </div>
            @break

        @case('quizzes')
            <div class="td-panels td-panels--2">
                <x-td.panel title="This course's quizzes">
                    @forelse ($quizzes as $q)
                        <p class="m-0 flex flex-wrap justify-between gap-2 border-t border-td-line py-2 first:border-t-0">
                            <a class="td-link" href="{{ route('teach.materials.assessments.show', $q->id) }}">{{ $q->title }}</a>
                            <span class="text-td-ink-2">{{ $q->questions_count }} {{ \Illuminate\Support\Str::plural('question', $q->questions_count) }}, pass at {{ $q->pass_percent }}%</span>
                        </p>
                    @empty
                        <x-td.state kind="empty" title="No quizzes yet">A quiz checks understanding inside the course. Write questions in a question bank, make a quiz here, add the questions, then add a Quiz lesson.</x-td.state>
                    @endforelse
                </x-td.panel>
                <x-td.panel title="New quiz">
                    <form method="POST" action="{{ route('teach.courses.quizzes.store', $course) }}" class="grid gap-4">
                        @csrf
                        <x-td.field label="Quiz title" for="quiz-title"><x-td.input name="title" id="quiz-title" maxlength="160" required /></x-td.field>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-td.field label="Pass mark (%)" for="pass_percent"><x-td.input name="pass_percent" type="number" min="0" max="100" value="50" /></x-td.field>
                            <x-td.field label="Attempts allowed" for="max_attempts" optional><x-td.input name="max_attempts" type="number" min="1" max="100" /></x-td.field>
                        </div>
                        <div><x-td.button type="submit" variant="primary">Create quiz</x-td.button></div>
                    </form>
                </x-td.panel>
            </div>
            @break

        @case('price')
            <form method="POST" action="{{ route('teach.courses.details', $course) }}" class="td-panels td-panels--2" data-teach-price-form data-preview-url="{{ route('teach.offerings.preview') }}" data-category="course">
                @csrf @method('PUT')
                <input type="hidden" name="tab" value="price"><input type="hidden" name="next" value="publish">
                <x-td.panel title="Price and terms">
                    <div class="grid gap-5">
                        <fieldset class="grid gap-2">
                            <legend class="td-label">Free or paid</legend>
                            <x-td.check type="radio" name="pricing" value="free" :checked="$course->pricing === 'free'">Free</x-td.check>
                            <x-td.check type="radio" name="pricing" value="paid" :checked="$course->pricing === 'paid'">Paid</x-td.check>
                        </fieldset>
                        <x-td.field label="Price ({{ $course->currency }})" for="price" optional hint="Only for a paid course.">
                            <x-td.input name="price" inputmode="decimal" :value="$course->price_minor ? number_format($course->price_minor / 100, 2, '.', '') : ''" :hint="true" data-teach-price />
                        </x-td.field>
                        <x-td.field label="Refund policy learners see before buying" for="refund_policy" :hint="'Required for a paid course. Earnings from a sale stay pending for '.(int) config('money.earnings.refund_window_days.course').' days, the refund window.'">
                            <x-td.textarea name="refund_policy" :value="$course->refund_policy" rows="3" :hint="true" />
                        </x-td.field>
                        <x-td.field label="Certificate" for="completion_rule" optional hint="A certificate of completion from Dot.Tutor, not an accredited qualification.">
                            <x-td.select name="completion_rule" :options="['all_lessons' => 'When every lesson is complete', 'all_lessons_and_quizzes' => 'When every lesson is complete and every quiz passed']" :value="$course->completion_rule?->value" placeholder="No certificate" :hint="true" />
                        </x-td.field>
                        <div><x-td.button type="submit" variant="primary">Save and continue</x-td.button></div>
                    </div>
                </x-td.panel>
                <x-td.panel title="What you keep from each sale">
                    <dl class="m-0 grid gap-2" data-teach-split aria-live="polite">
                        <div class="flex justify-between gap-3"><dt>Learner pays</dt><dd class="m-0 tabular-nums" data-split="price">{{ $m($split['price_minor']) }}</dd></div>
                        <div class="flex justify-between gap-3"><dt>Platform commission (<span data-split="percent">{{ rtrim(rtrim(number_format($split['commission_percent'], 2), '0'), '.') }}</span>%)</dt><dd class="m-0 tabular-nums" data-split="commission">−{{ $m($split['commission_minor']) }}</dd></div>
                        <div data-split="charges" class="grid gap-2">@foreach ($split['charges'] as $c)<div class="flex justify-between gap-3"><dt>{{ $c['label'] }}</dt><dd class="m-0 tabular-nums">−{{ $m($c['minor']) }}</dd></div>@endforeach</div>
                        <div class="flex justify-between gap-3 border-t border-td-line pt-2 text-lg font-bold"><dt>You keep</dt><dd class="m-0 tabular-nums" data-split="net">{{ $m($split['net_minor']) }}</dd></div>
                    </dl>
                    <p class="mb-0 mt-3 text-sm text-td-ink-2" data-split="status">Worked out with the course commission rule in force now. Each sale keeps the rule it was priced with. Your discount codes also work on courses.</p>
                </x-td.panel>
            </form>
            @break

        @case('publish')
            <div class="td-panels td-panels--2">
                <x-td.panel title="Before you publish">
                    @if ($problems === [])
                        <x-td.notice tone="success" title="Ready to publish">Every check passes. Publishing freezes this version: learners who enrol get exactly this, and later edits wait for your next publish.</x-td.notice>
                    @else
                        <p class="mt-0">Fix these first:</p>
                        <ul class="grid gap-2 pl-5">
                            @foreach ($problems as $p)<li>{{ $p }}</li>@endforeach
                        </ul>
                    @endif
                    <div class="mt-4 flex flex-wrap gap-3">
                        <form method="POST" action="{{ route('learning.studio.courses.publish', $course) }}">@csrf
                            <x-td.button type="submit" variant="primary" :disabled="$problems !== []">{{ $course->current_version_id ? 'Publish changes as a new version' : 'Publish course' }}</x-td.button>
                        </form>
                        @if ($course->status->value === 'published')
                            <form method="POST" action="{{ route('learning.studio.courses.archive', $course) }}">@csrf<x-td.button type="submit" variant="quiet">Archive (enrolled learners keep access)</x-td.button></form>
                        @endif
                    </div>
                </x-td.panel>
                <x-td.panel title="Versions">
                    @if ($draft['versions'] === [])
                        <p class="m-0 text-td-ink-2">Not published yet.</p>
                    @else
                        <ul class="m-0 grid list-none gap-1 p-0">
                            @foreach (array_reverse($draft['versions']) as $v)
                                <li>Version {{ $v['number'] }}, published {{ \Carbon\CarbonImmutable::parse($v['published_at'])->timezone($course->owner?->tutorProfile?->scheduleTimezone() ?? config('app.timezone'))->format('j M Y, H:i') }}</li>
                            @endforeach
                        </ul>
                        <p class="mb-0 mt-3 text-sm text-td-ink-2">Enrolled learners stay on the version they joined until they choose to move to the newest; completed lessons carry over.</p>
                    @endif
                </x-td.panel>
            </div>
            <x-td.panel title="Look at it as a learner" class="mt-6">
                @if ($lessonCount === 0)
                    <p class="m-0 text-td-ink-2">Add lessons to preview them.</p>
                @else
                    <ul class="m-0 grid list-none gap-1 p-0 sm:grid-cols-2">
                        @foreach ($draft['sections'] as $section)
                            @foreach ($section['lessons'] as $lesson)
                                <li><a class="td-link" href="{{ route('learning.studio.lessons.preview', $lesson['id']) }}">{{ $section['title'] }}: {{ $lesson['title'] }}</a></li>
                            @endforeach
                        @endforeach
                    </ul>
                @endif
            </x-td.panel>
            @break

        @case('learners')
            <x-td.panel title="Learners and their progress" flush>
                @if ($learners->isEmpty())
                    <div class="p-5"><x-td.state kind="empty" title="No learners yet">After you publish, learners who enrol appear here with how far they are. You see their name, version and progress only.</x-td.state></div>
                @else
                    <x-td.table caption="Learners in this course" caption-hidden dense>
                        <thead><tr><th scope="col">Learner</th><th scope="col">Enrolled</th><th scope="col" class="is-num">Version</th><th scope="col">Progress</th><th scope="col">Completed</th></tr></thead>
                        <tbody>
                            @foreach ($learners as $l)
                                <tr>
                                    <th scope="row">{{ $l['name'] }}</th>
                                    <td class="tabular-nums">{{ $l['enrolled_at']->format('j M Y') }}</td>
                                    <td class="is-num">{{ $l['version'] ?? '—' }}</td>
                                    <td><x-td.progress :label="'Progress for '.$l['name']" :value="$l['completed']" :max="max(1, $l['total'])" :display="$l['completed'].' of '.$l['total']" /></td>
                                    <td>{{ $l['completed_at'] ? \Carbon\CarbonImmutable::parse($l['completed_at'])->format('j M Y') : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </x-td.table>
                @endif
            </x-td.panel>
            @break
    @endswitch
</x-layouts::workspace>
