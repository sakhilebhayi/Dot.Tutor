<?php

namespace App\Domain\Learning\Services;

use App\Domain\Learning\Contracts\Seams\AuditTrail;
use App\Domain\Learning\Contracts\Seams\SafeguardingCheck;
use App\Domain\Learning\Contracts\Seams\TutorStanding;
use App\Domain\Learning\Enums\AssessmentKind;
use App\Domain\Learning\Enums\CourseStatus;
use App\Domain\Learning\Enums\LessonType;
use App\Domain\Learning\Models\Assessment;
use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\CourseVersion;
use App\Domain\Learning\Models\Lesson;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Publish checks and versioning. Publishing freezes the course into an immutable CourseVersion snapshot; learners
 * read the snapshot they enrolled on, so later draft edits never change what an enrolled learner sees until they
 * move to the newest version themselves.
 */
class CoursePublisher
{
    public function __construct(
        private TutorStanding $tutors,
        private SafeguardingCheck $safeguarding,
        private AuditTrail $audit,
        private VideoUrlPolicy $videoUrls,
    ) {}

    /**
     * Everything that stops the course from being published, in plain words. Empty means it can publish.
     *
     * @return list<string>
     */
    public function problems(Course $course, ?User $publisher = null): array
    {
        $problems = [];
        $course->loadMissing('sections.lessons');

        if (trim((string) $course->title) === '') {
            $problems[] = 'Give the course a title.';
        }
        if (mb_strlen(trim((string) $course->description)) < 30) {
            $problems[] = 'Describe the course in at least 30 characters.';
        }
        if ($course->subject_id === null) {
            $problems[] = 'Choose the subject.';
        }
        if (count(array_filter($course->objectives ?? [])) === 0) {
            $problems[] = 'Add at least one learning objective.';
        }
        if ($course->pricing === null) {
            $problems[] = 'Choose whether the course is free or paid.';
        } elseif ($course->pricing === 'paid') {
            if ((int) $course->price_minor <= 0) {
                $problems[] = 'Set a price above zero for a paid course.';
            }
            if (trim((string) $course->refund_policy) === '') {
                $problems[] = 'Write the refund policy learners see before they buy.';
            }
        }

        if ($course->sections->isEmpty()) {
            $problems[] = 'Add at least one section with a lesson. A course cannot publish empty.';
        }
        foreach ($course->sections as $section) {
            if ($section->lessons->isEmpty()) {
                $problems[] = "Section \"{$section->title}\" has no lessons.";
            }
            foreach ($section->lessons as $lesson) {
                if ($message = $this->lessonProblem($lesson, $course)) {
                    $problems[] = "Lesson \"{$lesson->title}\": {$message}";
                }
            }
        }

        $owner = $course->owner;
        if ($owner === null || ! $this->tutors->isApprovedTutor($owner)) {
            $problems[] = 'Only an approved tutor can publish a course. Finish your tutor profile and verification first.';
        }
        if ($publisher !== null) {
            $decision = $this->safeguarding->allows($publisher, 'publish', $course);
            if (! $decision->allowed) {
                $problems[] = $decision->reason ?? 'Publishing is not allowed for this account.';
            }
        }

        return $problems;
    }

    public function publish(Course $course, User $publisher): CourseVersion
    {
        $problems = $this->problems($course, $publisher);
        if ($problems !== []) {
            throw ValidationException::withMessages(['course' => $problems]);
        }

        $version = DB::transaction(function () use ($course, $publisher) {
            $locked = Course::query()->lockForUpdate()->findOrFail($course->id);
            $number = (int) CourseVersion::query()->where('course_id', $locked->id)->max('number') + 1;
            $version = CourseVersion::create([
                'course_id' => $locked->id,
                'number' => $number,
                'snapshot' => $this->snapshot($course->fresh(['sections.lessons', 'materials'])),
                'published_by' => $publisher->id,
                'published_at' => now(),
            ]);
            $locked->forceFill([
                'status' => CourseStatus::Published,
                'published_at' => $locked->published_at ?? now(),
                'current_version_id' => $version->id,
            ])->save();

            return $version;
        });

        $this->audit->record($publisher, 'learning.course.published', $course, ['version' => $version->number]);

        return $version;
    }

    /** Has the draft changed since the current published version? */
    public function hasUnpublishedChanges(Course $course): bool
    {
        $current = $course->currentVersion;
        if ($current === null) {
            return true;
        }

        return $this->snapshot($course->fresh(['sections.lessons', 'materials'])) != $current->snapshot;
    }

    /** @return array<string, mixed> */
    public function snapshot(Course $course): array
    {
        return [
            'course' => [
                'title' => $course->title,
                'description' => $course->description,
                'objectives' => array_values(array_filter($course->objectives ?? [])),
                'prerequisites' => array_values(array_filter($course->prerequisites ?? [])),
                'workload_minutes' => $course->workload_minutes,
                'original_locale' => $course->original_locale,
                'subject_id' => $course->subject_id,
                'curriculum_id' => $course->curriculum_id,
                'level_id' => $course->level_id,
                'completion_rule' => $course->completion_rule?->value,
            ],
            'sections' => $course->sections->map(fn ($section) => [
                'id' => $section->id,
                'title' => $section->title,
                'lessons' => $section->lessons->map(fn (Lesson $lesson) => [
                    'id' => $lesson->id,
                    'title' => $lesson->title,
                    'type' => $lesson->type->value,
                    'body' => $lesson->body,
                    'external_url' => $lesson->external_url,
                    'media_path' => $lesson->media_path,
                    'media_mime' => $lesson->media_mime,
                    'media_size' => $lesson->media_size,
                    'assessment_id' => $lesson->assessment_id,
                    'duration_minutes' => $lesson->duration_minutes,
                    'is_preview' => (bool) $lesson->is_preview,
                ])->values()->all(),
            ])->values()->all(),
            'materials' => $course->materials->sortBy('id')->map(fn ($material) => [
                'id' => $material->id,
                'lesson_id' => $material->lesson_id,
                'title' => $material->title,
                'path' => $material->path,
                'mime' => $material->mime,
                'size' => $material->size,
                'original_name' => $material->original_name,
                'permission' => $material->permission->value,
            ])->values()->all(),
        ];
    }

    private function lessonProblem(Lesson $lesson, Course $course): ?string
    {
        return match ($lesson->type) {
            LessonType::Text, LessonType::Practice => trim((string) $lesson->body) === '' ? 'write the lesson content.' : null,
            LessonType::Video, LessonType::Document, LessonType::Image, LessonType::Slides => $lesson->media_path === null
                ? 'upload the file.'
                : (Storage::disk('learning')->exists($lesson->media_path) ? null : 'the uploaded file is missing; upload it again.'),
            LessonType::VideoUrl => $this->videoUrls->problem((string) $lesson->external_url),
            LessonType::Quiz => $this->quizProblem($lesson, $course),
        };
    }

    private function quizProblem(Lesson $lesson, Course $course): ?string
    {
        $assessment = $lesson->assessment_id ? Assessment::query()->find($lesson->assessment_id) : null;
        if ($assessment === null || $assessment->owner_id !== $course->owner_id) {
            return 'choose one of your quizzes.';
        }
        if ($assessment->kind !== AssessmentKind::CourseQuiz || $assessment->course_id !== $course->id) {
            return 'the quiz must be a quiz for this course.';
        }
        if (! $assessment->questions()->exists()) {
            return 'the quiz has no questions.';
        }

        return null;
    }
}
