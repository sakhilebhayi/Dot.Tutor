<?php

namespace App\Domain\Classroom\Contracts;

use App\Models\User;

/**
 * Follow-up work after a lesson goes through learning's assessment assignment (the learner sees it in Practice).
 * The default implementation applies learning's own rules (only the author's tutor assessments, only learners the
 * tutor teaches, the safeguarding check) through learning's ports.
 */
interface FollowUpAssigner
{
    /** @return list<array{id: int, title: string}> the tutor's own assessments that can be assigned */
    public function assignable(User $tutor): array;

    /**
     * $inLesson: the caller has checked the learner was booked into a lesson with this tutor (a group seat counts,
     * which learning's own "has taught" read does not see).
     *
     * @return array{assignment_id: int, assessment_id: int, learner_id: int, due_at: ?string}
     */
    public function assign(User $tutor, int $assessmentId, User $learner, ?\DateTimeInterface $dueAt, bool $inLesson = false): array;
}
