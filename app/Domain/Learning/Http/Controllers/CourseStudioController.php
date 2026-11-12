<?php

namespace App\Domain\Learning\Http\Controllers;

use App\Domain\Community\Support\Names;
use App\Domain\Learning\Contracts\Seams\AcademicScopeLookup;
use App\Domain\Learning\Contracts\Seams\TutorStanding;
use App\Domain\Learning\Enums\AssessmentKind;
use App\Domain\Learning\Enums\CompletionRule;
use App\Domain\Learning\Enums\CourseStatus;
use App\Domain\Learning\Enums\LessonType;
use App\Domain\Learning\Enums\MaterialPermission;
use App\Domain\Learning\Models\Assessment;
use App\Domain\Learning\Models\Bundle;
use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\CourseMaterial;
use App\Domain\Learning\Models\CourseSection;
use App\Domain\Learning\Models\Lesson;
use App\Domain\Learning\Services\CoursePublisher;
use App\Domain\Learning\Services\FileIntake;
use App\Domain\Learning\Services\ProgressService;
use App\Domain\Learning\Services\VideoUrlPolicy;
use App\Domain\Learning\Support\CoursePresenter;
use App\Models\Subject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** The course creator's studio: draft, structure, uploads, pricing, publish, learners' progress, bundles. */
class CourseStudioController extends LearningController
{
    public function __construct(
        private CoursePublisher $publisher,
        private CoursePresenter $presenter,
        private FileIntake $files,
        private AcademicScopeLookup $academic,
        private VideoUrlPolicy $videoUrls,
    ) {}

    public function index(Request $request): JsonResponse|View
    {
        $courses = Course::query()->where('owner_id', $request->user()->id)->latest('id')->get();
        if ($this->wantsJson($request)) {
            return response()->json(['data' => $courses->map(fn (Course $c) => [
                'id' => $c->id, 'title' => $c->title, 'status' => $c->status->value, 'pricing' => $c->pricing,
                'price_minor' => $c->price_minor, 'currency' => $c->currency,
                'enrolments' => $c->enrolments()->where('status', 'active')->count(),
            ])]);
        }

        return view('learning.studio.create', [
            'courses' => $courses,
            'subjects' => Subject::query()->orderBy('name')->get(['id', 'name']),
            'canPublish' => app(TutorStanding::class)->isApprovedTutor($request->user()),
        ]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
            'subject_id' => ['nullable', 'integer'],
            'original_locale' => ['nullable', 'string', 'max:20', 'regex:/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})*$/'],
        ]);
        $this->assertAcademic($data);

        $course = Course::create([
            'owner_id' => $request->user()->id,
            'slug' => Str::slug(Str::limit($data['title'], 60, '')).'-'.Str::lower(Str::random(6)),
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'subject_id' => $data['subject_id'] ?? null,
            'original_locale' => $data['original_locale'] ?? 'en',
            'currency' => config('tutor.currency', 'ZAR'),
            'status' => CourseStatus::Draft,
        ]);

        return $this->wantsJson($request)
            ? response()->json(['data' => $this->presenter->draft($course)], 201)
            : redirect()->route('learning.studio.courses.show', $course)->with('status', 'Draft created. Add sections and lessons next.');
    }

    public function show(Request $request, Course $course): JsonResponse|View
    {
        Gate::authorize('manage', $course);
        $payload = $this->presenter->draft($course);
        $payload['publish_problems'] = $this->publisher->problems($course, $request->user());
        $payload['has_unpublished_changes'] = $course->current_version_id ? $this->publisher->hasUnpublishedChanges($course) : null;

        if ($this->wantsJson($request)) {
            return response()->json(['data' => $payload]);
        }

        return view('learning.studio.builder', [
            'course' => $course,
            'draft' => $payload,
            'subjects' => Subject::query()->orderBy('name')->get(['id', 'name']),
            'quizzes' => Assessment::query()->where('owner_id', $request->user()->id)->where('course_id', $course->id)
                ->where('kind', AssessmentKind::CourseQuiz->value)->get(['id', 'title']),
        ]);
    }

    public function update(Request $request, Course $course): JsonResponse|RedirectResponse
    {
        Gate::authorize('manage', $course);
        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'subject_id' => ['sometimes', 'nullable', 'integer'],
            'curriculum_id' => ['sometimes', 'nullable', 'integer'],
            'level_id' => ['sometimes', 'nullable', 'integer'],
            'original_locale' => ['sometimes', 'required', 'string', 'max:20', 'regex:/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})*$/'],
            'objectives' => ['sometimes', 'array', 'max:20'],
            'objectives.*' => ['nullable', 'string', 'max:300'],
            'prerequisites' => ['sometimes', 'array', 'max:20'],
            'prerequisites.*' => ['nullable', 'string', 'max:300'],
            'workload_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
            'pricing' => ['sometimes', 'nullable', Rule::in(['free', 'paid'])],
            'price_minor' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000000'],
            'currency' => ['sometimes', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'refund_policy' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'completion_rule' => ['sometimes', 'nullable', Rule::in(CompletionRule::values())],
        ]);
        $merged = array_merge($course->only(['curriculum_id', 'level_id', 'subject_id']), array_intersect_key($data, array_flip(['curriculum_id', 'level_id', 'subject_id'])));
        $this->assertAcademic($merged);
        foreach (['objectives', 'prerequisites'] as $list) {
            if (array_key_exists($list, $data)) {
                $data[$list] = array_values(array_filter(array_map(fn ($line) => trim((string) $line), $data[$list]), fn ($line) => $line !== ''));
            }
        }
        if (($data['pricing'] ?? $course->pricing) === 'free') {
            $data['price_minor'] = null;
        }
        $course->fill($data)->save();

        return $this->wantsJson($request)
            ? response()->json(['data' => $this->presenter->draft($course->fresh())])
            : back()->with('status', 'Course details saved.');
    }

    public function publish(Request $request, Course $course): JsonResponse|RedirectResponse
    {
        Gate::authorize('manage', $course);
        $version = $this->publisher->publish($course, $request->user());

        return $this->wantsJson($request)
            ? response()->json(['data' => ['version' => $version->number, 'published_at' => $version->published_at->toIso8601String()]], 201)
            : back()->with('status', "Published as version {$version->number}.");
    }

    public function archive(Request $request, Course $course): JsonResponse|RedirectResponse
    {
        Gate::authorize('manage', $course);
        if ($course->status !== CourseStatus::Published) {
            throw ValidationException::withMessages(['course' => 'Only a published course can be archived.']);
        }
        $course->forceFill(['status' => CourseStatus::Archived])->save();

        return $this->wantsJson($request) ? response()->json(['data' => ['status' => 'archived']]) : back()->with('status', 'Course archived. Enrolled learners keep access.');
    }

    public function storeSection(Request $request, Course $course): JsonResponse|RedirectResponse
    {
        Gate::authorize('manage', $course);
        $data = $request->validate(['title' => ['required', 'string', 'max:160']]);
        $section = $course->sections()->create([
            'title' => $data['title'],
            'position' => (int) $course->sections()->max('position') + 1,
        ]);

        return $this->wantsJson($request) ? response()->json(['data' => $section->only(['id', 'title', 'position'])], 201) : back()->with('status', 'Section added.');
    }

    public function updateSection(Request $request, CourseSection $section): JsonResponse|RedirectResponse
    {
        Gate::authorize('manage', $section->course);
        $section->fill($request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:160'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:10000'],
        ]))->save();

        return $this->wantsJson($request) ? response()->json(['data' => $section->only(['id', 'title', 'position'])]) : back()->with('status', 'Section saved.');
    }

    public function destroySection(Request $request, CourseSection $section): JsonResponse|RedirectResponse
    {
        $course = $section->course;
        Gate::authorize('manage', $course);
        foreach ($section->lessons as $lesson) {
            $this->forgetMedia($course, $lesson->media_path);
        }
        $section->delete();

        return $this->wantsJson($request) ? response()->json(null, 204) : back()->with('status', 'Section removed from the draft.');
    }

    public function storeLesson(Request $request, CourseSection $section): JsonResponse|RedirectResponse
    {
        $course = $section->course;
        Gate::authorize('manage', $course);
        $data = $this->validateLesson($request, $course, creating: true);

        $lesson = new Lesson([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'position' => (int) $section->lessons()->max('position') + 1,
        ]);
        $this->fillLesson($request, $course, $lesson, $data);

        return $this->wantsJson($request) ? response()->json(['data' => ['id' => $lesson->id, 'title' => $lesson->title, 'type' => $lesson->type->value]], 201) : back()->with('status', 'Lesson added.');
    }

    public function updateLesson(Request $request, Lesson $lesson): JsonResponse|RedirectResponse
    {
        $course = $lesson->course;
        Gate::authorize('manage', $course);
        $data = $this->validateLesson($request, $course, creating: false, current: $lesson);
        $this->fillLesson($request, $course, $lesson, $data);

        return $this->wantsJson($request) ? response()->json(['data' => ['id' => $lesson->id, 'title' => $lesson->title, 'type' => $lesson->type->value]]) : back()->with('status', 'Lesson saved.');
    }

    public function destroyLesson(Request $request, Lesson $lesson): JsonResponse|RedirectResponse
    {
        $course = $lesson->course;
        Gate::authorize('manage', $course);
        $this->forgetMedia($course, $lesson->media_path);
        $lesson->delete();

        return $this->wantsJson($request) ? response()->json(null, 204) : back()->with('status', 'Lesson removed from the draft.');
    }

    public function storeMaterial(Request $request, Course $course): JsonResponse|RedirectResponse
    {
        Gate::authorize('manage', $course);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'lesson_id' => ['nullable', 'integer', Rule::exists('learning_lessons', 'id')->where('course_id', $course->id)],
            'permission' => ['required', Rule::in(MaterialPermission::values())],
            'file' => ['required', 'file'],
        ]);
        $stored = $this->files->accept($request->file('file'), 'material', "courses/{$course->id}/materials");
        $material = CourseMaterial::create([
            'course_id' => $course->id,
            'lesson_id' => $data['lesson_id'] ?? null,
            'title' => $data['title'],
            'path' => $stored->path,
            'mime' => $stored->mime,
            'size' => $stored->size,
            'original_name' => $stored->originalName,
            'permission' => $data['permission'],
        ]);

        return $this->wantsJson($request) ? response()->json(['data' => ['id' => $material->id, 'title' => $material->title, 'permission' => $material->permission->value]], 201) : back()->with('status', 'Material uploaded.');
    }

    public function destroyMaterial(Request $request, CourseMaterial $material): JsonResponse|RedirectResponse
    {
        $course = $material->course;
        Gate::authorize('manage', $course);
        $this->forgetMedia($course, $material->path);
        $material->delete();

        return $this->wantsJson($request) ? response()->json(null, 204) : back()->with('status', 'Material removed from the draft.');
    }

    /** Learners' progress for the creator: name, version, percent, completion. Nothing else about them. */
    public function learners(Request $request, Course $course, ProgressService $progress): JsonResponse
    {
        Gate::authorize('manage', $course);
        $rows = $course->enrolments()->with(['user:id,name', 'version:id,number,snapshot'])->where('status', 'active')->orderBy('id')->get()
            ->map(function ($enrolment) use ($progress) {
                $summary = $progress->summary($enrolment);

                return [
                    'learner' => $enrolment->user ? Names::short($enrolment->user) : 'Deleted account',
                    'enrolled_at' => $enrolment->created_at->toIso8601String(),
                    'version' => $enrolment->version?->number,
                    'completed_lessons' => $summary['completed'],
                    'total_lessons' => $summary['total'],
                    'percent' => $summary['percent'],
                    'completed_at' => $summary['completed_at'],
                ];
            });

        return response()->json(['data' => $rows]);
    }

    public function storeBundle(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:3000'],
            'price_minor' => ['required', 'integer', 'min:1', 'max:100000000'],
            'course_ids' => ['required', 'array', 'min:2', 'max:20'],
            'course_ids.*' => ['integer', 'distinct'],
        ]);
        $courses = Course::query()->whereIn('id', $data['course_ids'])->where('owner_id', $request->user()->id)
            ->where('status', CourseStatus::Published->value)->get();
        if ($courses->count() !== count($data['course_ids'])) {
            throw ValidationException::withMessages(['course_ids' => 'A bundle can only contain your own published courses.']);
        }
        if ($courses->pluck('currency')->unique()->count() > 1) {
            throw ValidationException::withMessages(['course_ids' => 'All courses in a bundle must use the same currency.']);
        }
        $bundle = Bundle::create([
            'owner_id' => $request->user()->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'price_minor' => $data['price_minor'],
            'currency' => $courses->first()->currency,
            'status' => 'published',
        ]);
        $bundle->courses()->attach($courses->pluck('id'));

        return $this->wantsJson($request) ? response()->json(['data' => ['id' => $bundle->id, 'title' => $bundle->title]], 201) : back()->with('status', 'Bundle created.');
    }

    /** @param  array<string, mixed>  $data */
    private function assertAcademic(array $data): void
    {
        $errors = $this->academic->validate($data['curriculum_id'] ?? null, $data['level_id'] ?? null, $data['subject_id'] ?? null);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @return array<string, mixed> */
    private function validateLesson(Request $request, Course $course, bool $creating, ?Lesson $current = null): array
    {
        $data = $request->validate([
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:160'],
            'type' => [$creating ? 'required' : 'sometimes', Rule::in(LessonType::values())],
            'body' => ['nullable', 'string', 'max:100000'],
            // https only, whatever the lesson type: the link is rendered as an href (R-47 security S-17).
            'external_url' => ['nullable', 'string', 'max:2048', 'url:https'],
            'assessment_id' => ['nullable', 'integer'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'is_preview' => ['nullable', 'boolean'],
            'position' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'file' => ['nullable', 'file'],
        ]);
        $type = LessonType::from($data['type'] ?? $current->type->value);
        if ($type === LessonType::VideoUrl && isset($data['external_url']) && ($problem = $this->videoUrls->problem($data['external_url'])) !== null) {
            throw ValidationException::withMessages(['external_url' => ucfirst($problem)]);
        }
        if ($type === LessonType::Quiz && isset($data['assessment_id'])) {
            $ok = Assessment::query()->whereKey($data['assessment_id'])->where('owner_id', $course->owner_id)
                ->where('course_id', $course->id)->where('kind', AssessmentKind::CourseQuiz->value)->exists();
            if (! $ok) {
                throw ValidationException::withMessages(['assessment_id' => 'Choose one of your quizzes for this course.']);
            }
        }
        if ($request->hasFile('file') && ! $type->usesUpload()) {
            throw ValidationException::withMessages(['file' => 'This lesson type does not take a file.']);
        }

        return $data;
    }

    /** @param  array<string, mixed>  $data */
    private function fillLesson(Request $request, Course $course, Lesson $lesson, array $data): void
    {
        $lesson->fill(collect($data)->except('file')->all());
        if ($request->hasFile('file')) {
            $category = match ($lesson->type) {
                LessonType::Video => 'video',
                LessonType::Image => 'image',
                LessonType::Slides => 'slides',
                default => 'document',
            };
            $stored = $this->files->accept($request->file('file'), $category, "courses/{$course->id}/lessons");
            $this->forgetMedia($course, $lesson->media_path);
            $lesson->forceFill([
                'media_path' => $stored->path,
                'media_mime' => $stored->mime,
                'media_size' => $stored->size,
                'media_original_name' => $stored->originalName,
            ]);
        }
        $lesson->save();
    }

    /**
     * Remove a file from a draft. Once a course has been published, files stay: a published version may still
     * point at them, and enrolled learners read those versions.
     */
    private function forgetMedia(Course $course, ?string $path): void
    {
        if ($path !== null && $course->versions()->doesntExist()) {
            Storage::disk('learning')->delete($path);
        }
    }
}
