<?php

namespace App\Domain\Learning\Services;

use App\Domain\Learning\Contracts\Seams\AuditTrail;
use App\Domain\Learning\Enums\CompletionRule;
use App\Domain\Learning\Enums\CourseStatus;
use App\Domain\Learning\Enums\LessonType;
use App\Domain\Learning\Models\Attempt;
use App\Domain\Learning\Models\Certificate;
use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\Enrolment;
use App\Domain\Learning\Models\LessonProgress;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Enrolment, lesson completion, resume point, course progress and completion certificates. */
class ProgressService
{
    public function __construct(private CourseAccess $access, private AuditTrail $audit) {}

    /** Enrol for free, or through an entitlement for a paid course. Idempotent. */
    public function enrol(User $user, Course $course): Enrolment
    {
        if ($course->status !== CourseStatus::Published || $course->current_version_id === null) {
            throw ValidationException::withMessages(['course' => 'This course is not open for enrolment.']);
        }
        $existing = Enrolment::query()->where('course_id', $course->id)->where('user_id', $user->id)->first();
        if ($existing !== null && $existing->isActive()) {
            return $existing;
        }
        if (! $this->access->hasPurchaseAccess($user, $course)) {
            throw ValidationException::withMessages(['course' => 'This is a paid course. Buy it first to enrol.']);
        }
        $source = $course->isFree() ? 'free' : 'entitlement';

        try {
            $enrolment = Enrolment::query()->updateOrCreate(
                ['course_id' => $course->id, 'user_id' => $user->id],
                ['course_version_id' => $course->current_version_id, 'source' => $source, 'status' => 'active'],
            );
        } catch (UniqueConstraintViolationException) {
            $enrolment = Enrolment::query()->where('course_id', $course->id)->where('user_id', $user->id)->firstOrFail();
        }
        $this->audit->record($user, 'learning.course.enrolled', $course, ['source' => $source]);

        return $enrolment;
    }

    /** Move an enrolment onto the newest published version. Progress is kept for lessons that still exist. */
    public function moveToLatestVersion(Enrolment $enrolment): Enrolment
    {
        $course = $enrolment->course;
        if ($course->current_version_id !== null && $enrolment->course_version_id !== $course->current_version_id) {
            $enrolment->forceFill(['course_version_id' => $course->current_version_id, 'completed_at' => null])->save();
            $this->evaluateCompletion($enrolment->fresh());
        }

        return $enrolment->fresh();
    }

    public function savePosition(Enrolment $enrolment, int $lessonId, int $seconds): void
    {
        $this->assertLessonInVersion($enrolment, $lessonId);
        $seconds = max(0, min($seconds, 86_400));
        LessonProgress::query()->updateOrCreate(
            ['enrolment_id' => $enrolment->id, 'lesson_id' => $lessonId],
            ['position_seconds' => $seconds],
        );
        $enrolment->forceFill(['resume_lesson_id' => $lessonId, 'resume_position_seconds' => $seconds])->save();
    }

    /** Mark a lesson complete. Quiz lessons complete only through a submitted attempt (markQuizLessons). */
    public function completeLesson(Enrolment $enrolment, int $lessonId): void
    {
        $lesson = $this->assertLessonInVersion($enrolment, $lessonId);
        if ($lesson['type'] === LessonType::Quiz->value) {
            throw ValidationException::withMessages(['lesson' => 'A quiz lesson is completed by submitting the quiz.']);
        }
        $this->recordCompletion($enrolment, $lessonId);
        $this->evaluateCompletion($enrolment->fresh());
    }

    /** Called after an attempt is submitted: completes the quiz lessons that use this assessment. */
    public function markQuizLessons(Attempt $attempt): void
    {
        $assessment = $attempt->assessment;
        if ($assessment->course_id === null) {
            return;
        }
        $enrolment = Enrolment::query()->where('course_id', $assessment->course_id)
            ->where('user_id', $attempt->user_id)->where('status', 'active')->first();
        if ($enrolment === null || $enrolment->version === null) {
            return;
        }
        foreach ($enrolment->version->snapshot['sections'] ?? [] as $section) {
            foreach ($section['lessons'] as $lesson) {
                if ($lesson['type'] === LessonType::Quiz->value && (int) $lesson['assessment_id'] === $assessment->id) {
                    $this->recordCompletion($enrolment, (int) $lesson['id']);
                }
            }
        }
        $this->evaluateCompletion($enrolment->fresh());
    }

    /** @return array{completed: int, total: int, percent: int, completed_lesson_ids: list<int>, resume_lesson_id: ?int, resume_position_seconds: int, completed_at: ?string} */
    public function summary(Enrolment $enrolment): array
    {
        $lessonIds = $enrolment->version?->lessonIds() ?? [];
        $done = LessonProgress::query()->where('enrolment_id', $enrolment->id)->whereNotNull('completed_at')
            ->whereIn('lesson_id', $lessonIds ?: [0])->pluck('lesson_id')->map(fn ($id) => (int) $id)->all();
        $total = count($lessonIds);
        // Resume where the learner last was if that lesson is unfinished; otherwise at the first unfinished lesson.
        $last = (int) $enrolment->resume_lesson_id;
        $resume = $last && in_array($last, $lessonIds, true) && ! in_array($last, $done, true)
            ? $last
            : (collect($lessonIds)->first(fn ($id) => ! in_array($id, $done, true)) ?? ($lessonIds[0] ?? null));

        return [
            'completed' => count($done),
            'total' => $total,
            'percent' => $total === 0 ? 0 : intdiv(count($done) * 100, $total),
            'completed_lesson_ids' => array_values($done),
            'resume_lesson_id' => $resume,
            'resume_position_seconds' => $resume === (int) $enrolment->resume_lesson_id ? (int) $enrolment->resume_position_seconds : 0,
            'completed_at' => $enrolment->completed_at?->toIso8601String(),
        ];
    }

    /** Apply the course's completion rule to the enrolment's version; issue a certificate when it is met. */
    public function evaluateCompletion(Enrolment $enrolment): ?Certificate
    {
        $version = $enrolment->version;
        $rule = CompletionRule::tryFrom((string) ($version?->snapshot['course']['completion_rule'] ?? ''));
        $summary = $this->summary($enrolment);
        if ($version === null || $summary['total'] === 0 || $summary['completed'] < $summary['total']) {
            return null;
        }

        if ($rule === CompletionRule::AllLessonsAndQuizzes) {
            foreach ($version->snapshot['sections'] as $section) {
                foreach ($section['lessons'] as $lesson) {
                    if ($lesson['type'] !== LessonType::Quiz->value) {
                        continue;
                    }
                    $passed = Attempt::query()->where('assessment_id', $lesson['assessment_id'])
                        ->where('user_id', $enrolment->user_id)->where('status', 'submitted')
                        ->where('passed', true)->exists();
                    if (! $passed) {
                        return null;
                    }
                }
            }
        }

        if ($enrolment->completed_at === null) {
            $enrolment->forceFill(['completed_at' => now()])->save();
        }
        if ($rule === null) {
            return null; // The course defines no completion rule, so it issues no certificate.
        }

        return $this->issueCertificate($enrolment, $version->number);
    }

    private function issueCertificate(Enrolment $enrolment, int $versionNumber): Certificate
    {
        if ($existing = $enrolment->certificate) {
            return $existing;
        }
        $course = $enrolment->course;

        return DB::transaction(function () use ($enrolment, $course, $versionNumber) {
            $certificate = Certificate::query()->firstOrCreate(
                ['enrolment_id' => $enrolment->id],
                [
                    'course_id' => $course->id,
                    'user_id' => $enrolment->user_id,
                    'code' => $this->newCode(),
                    'learner_name' => $enrolment->user->name,
                    'course_title' => $course->title,
                    'tutor_name' => $course->owner?->name,
                    'course_version_number' => $versionNumber,
                    'issued_at' => now(),
                ],
            );
            $this->audit->record($enrolment->user, 'learning.certificate.issued', $certificate, ['course_id' => $course->id]);

            return $certificate;
        });
    }

    private function newCode(): string
    {
        do {
            $raw = strtoupper(bin2hex(random_bytes(6)));
            $code = implode('-', str_split($raw, 4));
        } while (Certificate::query()->where('code', $code)->exists());

        return $code;
    }

    private function recordCompletion(Enrolment $enrolment, int $lessonId): void
    {
        $progress = LessonProgress::query()->firstOrNew(['enrolment_id' => $enrolment->id, 'lesson_id' => $lessonId]);
        $progress->completed_at ??= now();
        $progress->save();
    }

    /** @return array<string, mixed> */
    private function assertLessonInVersion(Enrolment $enrolment, int $lessonId): array
    {
        $lesson = $enrolment->version?->lesson($lessonId);
        if ($lesson === null) {
            throw ValidationException::withMessages(['lesson' => 'That lesson is not part of your version of the course.']);
        }

        return $lesson;
    }
}
