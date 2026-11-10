<?php

namespace App\Domain\Ai\Seams;

use App\Domain\Learning\Contracts\ProgressFeed;
use App\Domain\Learning\Enums\AssessmentKind;
use App\Domain\Learning\Enums\ResourceStatus;
use App\Domain\Learning\Models\Assessment;
use App\Domain\Learning\Models\Assignment;
use App\Domain\Learning\Models\Attempt;
use App\Domain\Learning\Models\AttemptAnswer;
use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\Enrolment;
use App\Domain\Learning\Models\LibraryResource;
use App\Domain\Learning\Models\Question;
use App\Domain\Learning\Services\CourseAccess;
use App\Domain\Learning\Services\LibraryService;
use App\Models\TutorSession;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Every read the assistant makes of the learning domain, in one place (read-only; learning's own access services
 * decide visibility). The learning domain is merged, so this reads its models and services directly instead of a
 * new seam; if learning renames something, only this class changes.
 */
class LearningRecords
{
    /** An untimed attempt left "in progress" for longer than this no longer pauses anything. */
    private const UNTIMED_ACTIVE_HOURS = 6;

    public function __construct(
        private readonly ProgressFeed $progress,
        private readonly LibraryService $library,
        private readonly CourseAccess $courses,
    ) {}

    /** Timed attempts the learner is in the middle of right now (deadline not yet passed, 30 s grace). @return Collection<int, Attempt> */
    public function timedAttemptsInProgress(User $user): Collection
    {
        return Attempt::query()->where('user_id', $user->id)->where('status', 'in_progress')
            ->whereNotNull('deadline_at')->where('deadline_at', '>', now()->subSeconds(30))
            ->get();
    }

    /**
     * Questions the learner must answer on their own: assessments they are taking now, tutor assessments assigned to
     * them that they have not passed, and quizzes of courses they are enrolled in that they have not passed.
     * Practice assessments are not protected (they exist to learn from).
     *
     * @return Collection<int, Question>
     */
    public function protectedQuestions(User $user): Collection
    {
        $inProgress = Attempt::query()->where('user_id', $user->id)->where('status', 'in_progress')
            ->where(fn ($q) => $q->where('deadline_at', '>', now()->subSeconds(30))
                ->orWhere(fn ($q) => $q->whereNull('deadline_at')->where('started_at', '>', now()->subHours(self::UNTIMED_ACTIVE_HOURS))))
            ->pluck('assessment_id');

        $assigned = Assignment::query()->where('learner_id', $user->id)
            ->where(fn ($q) => $q->whereNull('due_at')->orWhere('due_at', '>', now()->subDays(7)))
            ->pluck('assessment_id');

        $enrolledCourses = Enrolment::query()->where('user_id', $user->id)->where('status', 'active')->pluck('course_id');
        $courseQuizzes = Assessment::query()->whereIn('course_id', $enrolledCourses)
            ->where('kind', AssessmentKind::CourseQuiz->value)->pluck('id');

        $passed = Attempt::query()->where('user_id', $user->id)->where('status', 'submitted')->where('passed', true)->pluck('assessment_id');

        $ids = $assigned->merge($courseQuizzes)->diff($passed)->merge($inProgress)->unique()->values();
        if ($ids->isEmpty()) {
            return collect();
        }
        $ids = Assessment::query()->whereIn('id', $ids)
            ->where(fn ($q) => $q->where('kind', '!=', AssessmentKind::Practice->value)->orWhereIn('id', $inProgress))
            ->pluck('id');

        return Question::query()->whereIn('id', function ($q) use ($ids) {
            $q->select('question_id')->from('learning_assessment_questions')->whereIn('assessment_id', $ids);
        })->get(['id', 'prompt', 'bank_id']);
    }

    /** The learner's own submitted answer to one question, with its question. */
    public function submittedAnswer(User $user, int $attemptId, int $questionId): ?AttemptAnswer
    {
        return AttemptAnswer::query()->with(['question.bank'])
            ->where('attempt_id', $attemptId)->where('question_id', $questionId)->where('user_id', $user->id)
            ->first();
    }

    public function attempt(int $attemptId): ?Attempt
    {
        return Attempt::query()->with('assessment')->find($attemptId);
    }

    /** @return list<array<string, mixed>> */
    public function mastery(User $user): array
    {
        return $this->progress->mastery($user);
    }

    /** Learners this tutor teaches: confirmed or completed sessions, active enrolments in their courses, assignments they set. @return list<int> */
    public function studentIdsOf(User $tutor): array
    {
        $profileId = $tutor->tutorProfile()->value('id');
        $sessions = $profileId ? TutorSession::query()->where('tutor_profile_id', $profileId)
            ->whereIn('status', ['confirmed', 'completed'])->pluck('student_id') : collect();
        $enrolled = Enrolment::query()->where('status', 'active')
            ->whereIn('course_id', Course::query()->where('owner_id', $tutor->id)->select('id'))->pluck('user_id');
        $assigned = Assignment::query()->where('assigned_by', $tutor->id)->pluck('learner_id');

        return $sessions->merge($enrolled)->merge($assigned)->filter()->unique()->reject(fn ($id) => $id === $tutor->id)->values()->all();
    }

    public function resourceIfReadable(User $user, int $resourceId): ?LibraryResource
    {
        $resource = LibraryResource::query()->find($resourceId);
        if ($resource === null || ! $this->library->canSee($user, $resource) || ! $this->library->canDownload($user, $resource)) {
            return null;
        }

        return $resource;
    }

    public function resourceVisible(User $user, LibraryResource $resource): bool
    {
        return $this->library->canSee($user, $resource);
    }

    /** Raw bytes of a library file the caller already authorised, or null when missing. */
    public function resourceBytes(LibraryResource $resource): ?string
    {
        $disk = Storage::disk('learning');

        return $resource->path && $disk->exists($resource->path) ? $disk->get($resource->path) : null;
    }

    public function courseVisible(User $user, Course $course): bool
    {
        return $this->courses->canViewOutline($user, $course);
    }

    /**
     * Published library resources that fit a subject, level and language, newest reviewed first. Metadata only.
     *
     * @return list<array{id: int, title: string, type: string, topic: ?string, language: string, quality: string, free: bool}>
     */
    public function libraryCandidates(?int $subjectId, ?int $levelId, ?string $topic, int $limit = 25): array
    {
        $query = LibraryResource::query()->where('status', ResourceStatus::Published->value);
        if ($subjectId) {
            $query->where('subject_id', $subjectId);
        }
        if ($levelId) {
            $query->where(fn ($q) => $q->where('level_id', $levelId)->orWhereNull('level_id'));
        }
        if ($topic) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_substr($topic, 0, 60)).'%';
            $query->orderByRaw('case when topic like ? or title like ? then 0 else 1 end', [$like, $like]);
        }

        return $query->orderByRaw("case when quality_tier in ('officially_sourced', 'reviewed') then 0 else 1 end")
            ->orderByDesc('id')->limit($limit)->get()
            ->map(fn (LibraryResource $r) => [
                'id' => $r->id,
                'title' => $r->title,
                'type' => $r->type->value,
                'topic' => $r->topic,
                'language' => $r->language,
                'quality' => $r->quality_tier->value,
                'free' => (bool) $r->is_free,
            ])->all();
    }
}
