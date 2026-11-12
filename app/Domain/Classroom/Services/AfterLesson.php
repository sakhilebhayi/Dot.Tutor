<?php

namespace App\Domain\Classroom\Services;

use App\Domain\Classroom\Contracts\FollowUpAssigner;
use App\Domain\Classroom\Events\FollowUpAssigned;
use App\Domain\Classroom\Events\LessonSummaryPublished;
use App\Domain\Classroom\Models\AttendanceSpan;
use App\Domain\Classroom\Models\Classroom;
use App\Domain\Classroom\Support\ClassroomException;
use App\Domain\Marketplace\Exceptions\MarketplaceException;
use App\Domain\Marketplace\Services\BookingFlow;
use App\Domain\Safety\Contracts\AuditLog;
use App\Domain\Safety\Services\ContactRedactor;
use App\Enums\SessionStatus;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Around the lesson: attendance from real joins, the tutor's private lesson notes, the summary the tutor writes for
 * the learners, follow-up work, and completing the lesson through the marketplace's own flow.
 */
class AfterLesson
{
    public function __construct(
        private readonly BookingFlow $bookings,
        private readonly FollowUpAssigner $followUps,
        private readonly AuditLog $audit,
        private readonly ClassroomChat $chat,
        private readonly ContactRedactor $redactor,
    ) {}

    /**
     * Who was in the room, when, and for how long (summed over their real joins). People booked in who never joined
     * are listed with zero minutes.
     *
     * @return list<array{user_id: int, name: string, role: string, first_joined: ?string, last_left: ?string, seconds: int, minutes: int, joins: int}>
     */
    public function attendance(Classroom $classroom): array
    {
        $spans = AttendanceSpan::query()->with('user:id,name')->where('classroom_id', $classroom->id)->orderBy('joined_at')->orderBy('id')->get()->groupBy('user_id');
        $rows = [];

        $booked = array_values(array_unique(array_filter([$classroom->tutorUserId(), ...$classroom->learnerIds()])));
        foreach (array_unique([...$booked, ...$spans->keys()->map(fn ($k) => (int) $k)->all()]) as $userId) {
            $list = $spans->get($userId, collect());
            $user = $list->first()?->user ?? User::query()->find($userId);
            $seconds = (int) $list->sum(fn (AttendanceSpan $s) => $s->seconds());
            $lastEnd = $list->map(fn (AttendanceSpan $s) => $s->left_at ?? $s->last_seen_at)->max();
            $rows[] = [
                'user_id' => (int) $userId,
                'name' => $user?->name ?? 'Former member',
                'role' => $list->first()?->role ?? ($userId === $classroom->tutorUserId() ? 'tutor' : 'learner'),
                'first_joined' => $list->first()?->joined_at?->toIso8601String(),
                'last_left' => $lastEnd?->toIso8601String(),
                'open' => $list->contains(fn (AttendanceSpan $s) => $s->left_at === null),
                'seconds' => $seconds,
                'minutes' => intdiv($seconds + 30, 60),
                'joins' => $list->count(),
            ];
        }

        return $rows;
    }

    public function saveTutorNotes(Classroom $classroom, User $tutor, ?string $notes): void
    {
        $classroom->forceFill(['tutor_notes' => $notes === null ? null : mb_substr($notes, 0, 20000)])->save();
    }

    /** The tutor writes (or edits) the summary; publishing shows it to the learners and their guardians. */
    public function saveSummary(Classroom $classroom, User $tutor, string $summary, bool $publish): void
    {
        $summary = trim(mb_substr($summary, 0, 8000));
        if ($summary === '') {
            throw new ClassroomException('empty_summary', 'Write the summary first.');
        }
        if ($this->chat->minorInLesson($classroom)) {
            $summary = $this->redactor->redact($summary)['text'];
        }

        $wasPublished = $classroom->summary_published_at !== null;
        $classroom->forceFill([
            'summary' => $summary,
            'summary_source' => 'tutor',
            'summary_published_at' => $publish ? ($classroom->summary_published_at ?? now()) : null,
        ])->save();

        if ($publish && ! $wasPublished) {
            $this->audit->record($tutor, 'classroom.summary_published', $classroom, ['tutor_session_id' => $classroom->tutor_session_id]);
            LessonSummaryPublished::dispatch($classroom, ['learner_ids' => $classroom->learnerIds()]);
        }
    }

    /** Complete the lesson through the marketplace (its state machine decides whether it can). */
    public function complete(Classroom $classroom, User $actor): bool
    {
        try {
            return $this->bookings->complete($classroom->session, $actor);
        } catch (MarketplaceException $e) {
            throw new ClassroomException($e->error, $e->getMessage(), $e->status);
        }
    }

    public function canComplete(Classroom $classroom): bool
    {
        return in_array($classroom->session->statusEnum(), [SessionStatus::InProgress, SessionStatus::Confirmed], true)
            && now()->gte($classroom->session->starts_at);
    }

    /** @return array<string, mixed> */
    public function assign(Classroom $classroom, User $tutor, int $assessmentId, int $learnerId, ?string $dueAt): array
    {
        if (! in_array($learnerId, $classroom->learnerIds(), true)) {
            throw new ClassroomException('not_in_lesson', 'Pick a learner from this lesson.');
        }
        $due = $dueAt ? Carbon::parse($dueAt) : null;
        if ($due && $due->isPast()) {
            throw new ClassroomException('due_in_past', 'Pick a due date in the future.');
        }
        $learner = User::query()->findOrFail($learnerId);
        $result = $this->followUps->assign($tutor, $assessmentId, $learner, $due, true);

        $this->audit->record($tutor, 'classroom.follow_up_assigned', $classroom, ['tutor_session_id' => $classroom->tutor_session_id, 'assessment_id' => $assessmentId, 'learner_id' => $learnerId]);
        FollowUpAssigned::dispatch($classroom, $result);

        return $result;
    }
}
