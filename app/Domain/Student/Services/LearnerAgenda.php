<?php

namespace App\Domain\Student\Services;

use App\Domain\Community\Models\Post;
use App\Domain\Community\Models\RoomMember;
use App\Domain\Learning\Contracts\ProgressFeed;
use App\Domain\Learning\Models\Assignment;
use App\Domain\Learning\Models\Attempt;
use App\Domain\Learning\Models\Enrolment;
use App\Domain\Learning\Services\ProgressService;
use App\Domain\Marketplace\Models\TutorSessionParticipant;
use App\Domain\Marketplace\Services\ReviewService;
use App\Enums\SessionStatus;
use App\Models\TutorSession;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * What is next for one learner, read from the owning domains (marketplace bookings and seats, learning enrolments,
 * assignments and progress). Nothing here is estimated or invented: an empty list means there is nothing.
 */
class LearnerAgenda
{
    public const OPEN_STATES = ['awaiting_guardian', 'pending', 'awaiting_payment', 'confirmed', 'in_progress'];

    public function __construct(
        private readonly ProgressService $progress,
        private readonly ProgressFeed $feed,
        private readonly ReviewService $reviews,
    ) {}

    /** Lessons booked by this learner that have not finished, soonest first. @return Collection<int, TutorSession> */
    public function upcomingLessons(User $user, int $limit = 6): Collection
    {
        return TutorSession::query()
            ->with(['tutorProfile.user', 'subject', 'offering'])
            ->where('student_id', $user->id)
            ->whereIn('status', self::OPEN_STATES)
            ->where('starts_at', '>=', now()->subHours(4))
            ->orderBy('starts_at')->orderBy('id')
            ->limit($limit)->get();
    }

    /** Group seats this learner holds on lessons still to come. @return Collection<int, TutorSessionParticipant> */
    public function upcomingSeats(User $user, int $limit = 6): Collection
    {
        return TutorSessionParticipant::query()
            ->with(['session.tutorProfile.user', 'session.subject', 'session.offering'])
            ->where('learner_id', $user->id)
            ->whereIn('status', ['awaiting_guardian', 'awaiting_payment', 'confirmed'])
            ->whereHas('session', fn ($q) => $q->where('starts_at', '>=', now()->subHours(4)))
            ->orderBy('id')
            ->limit($limit)->get();
    }

    /** Completed lessons this learner can still review. @return Collection<int, TutorSession> */
    public function lessonsToReview(User $user, int $limit = 3): Collection
    {
        return TutorSession::query()
            ->with(['tutorProfile.user', 'subject'])
            ->where('student_id', $user->id)
            ->where('status', SessionStatus::Completed->value)
            ->where('starts_at', '>=', now()->subDays(30))
            ->orderByDesc('starts_at')->orderByDesc('id')
            ->limit(10)->get()
            ->filter(fn (TutorSession $s) => $this->reviews->canReview($s, $user))
            ->take($limit)->values();
    }

    /**
     * Courses in progress with where to resume.
     *
     * @return list<array{enrolment: Enrolment, title: string, summary: array<string, mixed>}>
     */
    public function coursesInProgress(User $user, int $limit = 4): array
    {
        return Enrolment::query()->with(['course.currentVersion', 'version'])
            ->where('user_id', $user->id)->where('status', 'active')->whereNull('completed_at')
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->limit($limit)->get()
            ->filter(fn (Enrolment $e) => $e->course !== null)
            ->map(fn (Enrolment $e) => [
                'enrolment' => $e,
                'title' => $e->version?->snapshot['course']['title'] ?? $e->course->title,
                'summary' => $this->progress->summary($e),
            ])->values()->all();
    }

    /**
     * Work a tutor set that has not been handed in, earliest due first.
     *
     * @return list<array{assignment: Assignment, title: string, due_at: ?CarbonInterface, overdue: bool}>
     */
    public function workDue(User $user, int $limit = 5): array
    {
        $assignments = Assignment::query()->with('assessment')
            ->where('learner_id', $user->id)
            ->orderByRaw('due_at is null')->orderBy('due_at')->orderBy('id')
            ->limit(30)->get()
            ->filter(fn (Assignment $a) => $a->assessment !== null);

        $submitted = Attempt::query()->where('user_id', $user->id)->where('status', 'submitted')
            ->whereIn('assessment_id', $assignments->pluck('assessment_id')->all() ?: [0])
            ->pluck('assessment_id')->map(fn ($id) => (int) $id)->unique()->all();

        return $assignments
            ->reject(fn (Assignment $a) => in_array((int) $a->assessment_id, $submitted, true))
            ->take($limit)
            ->map(fn (Assignment $a) => [
                'assignment' => $a,
                'title' => $a->assessment->title,
                'due_at' => $a->due_at,
                'overdue' => $a->due_at !== null && $a->due_at->isPast(),
            ])->values()->all();
    }

    /**
     * Questions in rooms this learner belongs to that nobody has answered yet (community domain), newest first.
     * Empty when the community domain is not installed.
     *
     * @return Collection<int, Post>
     */
    public function unansweredInMyRooms(User $user, int $limit = 5): Collection
    {
        if (! class_exists(Post::class) || ! class_exists(RoomMember::class)) {
            return collect();
        }
        $post = Post::class;
        $roomIds = RoomMember::query()->where('user_id', $user->id)->where('status', 'active')->pluck('room_id')->all();

        return $post::query()->with('room')
            ->whereIn('room_id', $roomIds ?: [0])
            ->whereNull('parent_id')->where('kind', $post::QUESTION)->where('status', $post::VISIBLE)
            ->whereNull('resolved_at')->where('author_id', '!=', $user->id)
            ->whereDoesntHave('children', fn ($q) => $q->where('kind', $post::ANSWER)->where('status', $post::VISIBLE))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit($limit)->get();
    }

    /** @return array{label: string, based_on: array<string, mixed>, sentences: list<string>} */
    public function insights(User $user): array
    {
        return $this->feed->insights($user);
    }
}
