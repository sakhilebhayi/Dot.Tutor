<?php

namespace App\Domain\Learning\Http\Controllers;

use App\Domain\Learning\Enums\MaterialPermission;
use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\CourseMaterial;
use App\Domain\Learning\Models\Lesson;
use App\Domain\Learning\Services\CourseAccess;
use App\Domain\Learning\Services\ProgressService;
use App\Domain\Learning\Support\CoursePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Protected delivery. Lesson content, media and materials are only ever returned by these actions after CourseAccess
 * says yes for this user. Media sits on a private disk and is reached through short-lived signed URLs that are bound
 * to the signed-in user, re-authorised on every request, and served with range support for video seeking.
 */
class LearnController extends LearningController
{
    public function __construct(
        private CourseAccess $access,
        private ProgressService $progress,
        private CoursePresenter $presenter,
    ) {}

    public function learn(Request $request, Course $course, ?int $lesson = null): JsonResponse|View|RedirectResponse
    {
        $user = $request->user();
        ['version' => $version, 'preview_only' => $previewOnly, 'enrolment' => $enrolment] = $this->access->readingContext($user, $course);
        if ($version === null) {
            abort(404);
        }
        $summary = $enrolment ? $this->progress->summary($enrolment) : null;
        $lessonId = $lesson ?? $summary['resume_lesson_id'] ?? null;
        if ($lessonId === null && $previewOnly) {
            $lessonId = collect($this->presenter->outline($version))->flatMap(fn ($s) => $s['lessons'])->firstWhere('is_preview', true)['id'] ?? null;
        }
        $content = $lessonId ? $this->access->readableLesson($user, $course, $lessonId) : null;
        if ($lesson !== null && $content === null) {
            return $this->wantsJson($request)
                ? $this->error('forbidden', 'Enrol in this course to open this lesson.', 403)
                : abort(403, 'Enrol in this course to open this lesson.');
        }

        $payload = [
            'course' => ['id' => $course->id, 'title' => $version->snapshot['course']['title'], 'version' => $version->number],
            'preview_only' => $previewOnly,
            'outline' => $this->presenter->outline($version),
            'progress' => $summary,
            'lesson' => $content ? $this->lessonPayload($request, $course, $content, (string) $version->id) : null,
            'newer_version_available' => $enrolment !== null && $enrolment->course_version_id !== $course->current_version_id,
        ];
        if ($content) {
            $payload['materials'] = $this->materialsFor($request, $course, $version->snapshot['materials'] ?? [], $content['id'], $previewOnly);
        }

        if ($this->wantsJson($request)) {
            return response()->json(['data' => $payload]);
        }

        return view('learning.learn.lesson', ['course' => $course, 'data' => $payload]);
    }

    /** The owner's (or an operator's) preview of a draft lesson. */
    public function preview(Request $request, Lesson $lesson): JsonResponse|View
    {
        $course = $lesson->course;
        Gate::authorize('viewDraft', $course);
        $row = $lesson->only(['id', 'title', 'body', 'external_url', 'media_path', 'media_mime', 'assessment_id', 'duration_minutes', 'is_preview']) + ['type' => $lesson->type->value];
        $payload = ['course' => ['id' => $course->id, 'title' => $course->title, 'version' => null], 'preview_only' => false, 'draft' => true, 'outline' => [], 'progress' => null, 'lesson' => $this->lessonPayload($request, $course, $row, 'draft'), 'newer_version_available' => false, 'materials' => []];

        return $this->wantsJson($request) ? response()->json(['data' => $payload]) : view('learning.learn.lesson', ['course' => $course, 'data' => $payload]);
    }

    public function complete(Request $request, Course $course, int $lesson): JsonResponse|RedirectResponse
    {
        $enrolment = $this->access->activeEnrolment($request->user(), $course);
        if ($enrolment === null) {
            return $this->wantsJson($request) ? $this->error('not_enrolled', 'Enrol in this course first.', 403) : abort(403);
        }
        $this->progress->completeLesson($enrolment, $lesson);
        $summary = $this->progress->summary($enrolment->fresh());

        return $this->wantsJson($request)
            ? response()->json(['data' => $summary + ['certificate' => $enrolment->fresh()->certificate?->code]])
            : redirect()->route('learning.learn', [$course, $summary['resume_lesson_id']])->with('status', 'Lesson completed.');
    }

    public function position(Request $request, Course $course, int $lesson): JsonResponse
    {
        $enrolment = $this->access->activeEnrolment($request->user(), $course);
        if ($enrolment === null) {
            return $this->error('not_enrolled', 'Enrol in this course first.', 403);
        }
        $seconds = (int) $request->validate(['seconds' => ['required', 'integer', 'min:0', 'max:86400']])['seconds'];
        $this->progress->savePosition($enrolment, $lesson, $seconds);

        return response()->json(['data' => ['lesson_id' => $lesson, 'seconds' => $seconds]]);
    }

    public function progressSummary(Request $request, Course $course): JsonResponse
    {
        $enrolment = $this->access->activeEnrolment($request->user(), $course);
        if ($enrolment === null) {
            return $this->error('not_enrolled', 'You are not enrolled in this course.', 404);
        }

        return response()->json(['data' => $this->progress->summary($enrolment) + ['certificate' => $enrolment->certificate?->code]]);
    }

    public function upgrade(Request $request, Course $course): JsonResponse|RedirectResponse
    {
        $enrolment = $this->access->activeEnrolment($request->user(), $course);
        if ($enrolment === null) {
            return $this->wantsJson($request) ? $this->error('not_enrolled', 'Enrol in this course first.', 403) : abort(403);
        }
        $enrolment = $this->progress->moveToLatestVersion($enrolment);

        return $this->wantsJson($request)
            ? response()->json(['data' => ['version' => $enrolment->version?->number] + $this->progress->summary($enrolment)])
            : back()->with('status', 'You are now on the newest version of the course.');
    }

    /** Signed, user-bound, re-authorised media (video with range requests, images, documents, slides). */
    public function media(Request $request, Course $course, string $kind, int $item): BinaryFileResponse
    {
        $user = $request->user();
        abort_unless((int) $request->query('u') === $user->id, 403);
        $version = (string) $request->query('v');

        if ($version === 'draft') {
            Gate::authorize('viewDraft', $course);
            $lesson = Lesson::query()->where('course_id', $course->id)->findOrFail($item);
            [$path, $mime, $name] = [$lesson->media_path, $lesson->media_mime, $lesson->title];
        } else {
            $context = $this->access->readingContext($user, $course);
            abort_unless($context['version'] !== null && (string) $context['version']->id === $version, 403);
            $lesson = $this->access->readableLesson($user, $course, $item);
            abort_if($lesson === null, 403);
            [$path, $mime, $name] = [$lesson['media_path'], $lesson['media_mime'], $lesson['title']];
        }
        abort_if($path === null, 404);

        return $this->stream($path, (string) $mime, $name, inline: true);
    }

    public function material(Request $request, Course $course, int $material): BinaryFileResponse
    {
        $user = $request->user();
        if ($this->access->isStaff($user, $course)) {
            $row = CourseMaterial::query()->where('course_id', $course->id)->findOrFail($material);
            $found = ['path' => $row->path, 'mime' => $row->mime, 'title' => $row->title, 'permission' => $row->permission->value, 'original_name' => $row->original_name];
        } else {
            $found = $this->access->readableMaterial($user, $course, $material);
            abort_if($found === null, 403);
        }
        $inline = $found['permission'] === MaterialPermission::EnrolledViewOnly->value;

        return $this->stream($found['path'], $found['mime'], $found['title'], inline: $inline, extension: pathinfo($found['original_name'] ?? '', PATHINFO_EXTENSION));
    }

    private function stream(string $path, string $mime, string $title, bool $inline, ?string $extension = null): BinaryFileResponse
    {
        $disk = Storage::disk('learning');
        abort_unless($disk->exists($path), 404);
        $extension = $extension ?: pathinfo($path, PATHINFO_EXTENSION);
        $filename = (Str::slug($title) ?: 'file').($extension ? '.'.$extension : '');

        $response = new BinaryFileResponse($disk->path($path), 200, [
            'Content-Type' => $mime ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], public: false, autoEtag: false, autoLastModified: true);
        $response->setContentDisposition($inline ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename, Str::ascii($filename));

        return $response;
    }

    /**
     * @param  array<string, mixed>  $lesson
     * @return array<string, mixed>
     */
    private function lessonPayload(Request $request, Course $course, array $lesson, string $versionKey): array
    {
        $mediaUrl = null;
        if (! empty($lesson['media_path'])) {
            $mediaUrl = URL::temporarySignedRoute('learning.media', now()->addMinutes((int) config('learning.signed_url_minutes', 10)), [
                'course' => $course->id, 'kind' => 'lesson', 'item' => $lesson['id'], 'v' => $versionKey, 'u' => $request->user()->id,
            ]);
        }

        return [
            'id' => (int) $lesson['id'],
            'title' => $lesson['title'],
            'type' => $lesson['type'],
            'body_html' => $lesson['body'] !== null && $lesson['body'] !== ''
                ? (string) Str::markdown($lesson['body'], ['html_input' => 'strip', 'allow_unsafe_links' => false])
                : null,
            'external_url' => $lesson['external_url'],
            'media_url' => $mediaUrl,
            'media_mime' => $lesson['media_mime'],
            'assessment_id' => $lesson['assessment_id'] ? (int) $lesson['assessment_id'] : null,
            'duration_minutes' => $lesson['duration_minutes'],
            'is_preview' => (bool) $lesson['is_preview'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $materials
     * @return list<array<string, mixed>>
     */
    private function materialsFor(Request $request, Course $course, array $materials, int $lessonId, bool $previewOnly): array
    {
        return collect($materials)
            ->filter(fn ($m) => $m['lesson_id'] === null || (int) $m['lesson_id'] === $lessonId)
            ->filter(fn ($m) => ! $previewOnly || $m['permission'] === MaterialPermission::PublicPreview->value)
            ->map(fn ($m) => [
                'id' => $m['id'],
                'title' => $m['title'],
                'permission' => $m['permission'],
                'size' => $m['size'],
                'url' => route($request->is('api/*') ? 'api.learning.materials.show' : 'learning.materials.show', [$course, $m['id']]),
            ])->values()->all();
    }
}
