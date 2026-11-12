<?php

namespace App\Domain\Classroom\Seams;

use App\Domain\Classroom\Contracts\FollowUpAssigner;
use App\Domain\Classroom\Support\ClassroomException;
use App\Domain\Learning\Contracts\Seams\SafeguardingCheck;
use App\Domain\Learning\Contracts\Seams\TutorStanding;
use App\Domain\Learning\Enums\AssessmentKind;
use App\Domain\Learning\Models\Assessment;
use App\Domain\Learning\Models\Assignment;
use App\Models\User;

/**
 * Assigns through learning's own tables and ports with learning's rules, mirroring
 * Learning\Http\Controllers\AssessmentController::assign (learning has no assignment service to call; D-810).
 */
class LearningFollowUpAssigner implements FollowUpAssigner
{
    public function __construct(private readonly TutorStanding $tutors, private readonly SafeguardingCheck $safeguarding) {}

    public function assignable(User $tutor): array
    {
        return Assessment::query()->where('owner_id', $tutor->id)->where('kind', AssessmentKind::TutorAssessment->value)
            ->whereHas('questions')->orderBy('title')->get(['id', 'title'])
            ->map(fn (Assessment $a) => ['id' => $a->id, 'title' => $a->title])->all();
    }

    public function assign(User $tutor, int $assessmentId, User $learner, ?\DateTimeInterface $dueAt, bool $inLesson = false): array
    {
        $assessment = Assessment::query()->whereKey($assessmentId)->where('owner_id', $tutor->id)
            ->where('kind', AssessmentKind::TutorAssessment->value)->first();
        if ($assessment === null) {
            throw new ClassroomException('no_assessment', 'Pick one of your own tutor assessments.', 404);
        }
        if ($learner->id === $tutor->id || ! ($inLesson || $this->tutors->hasTaught($tutor, $learner))) {
            throw new ClassroomException('no_relationship', 'You can only assign work to learners you teach.', 403);
        }
        $decision = $this->safeguarding->allows($tutor, 'assign_work', $learner);
        if (! $decision->allowed) {
            throw new ClassroomException('not_allowed', $decision->reason ?? 'Assigning work to this learner is not allowed.', 403);
        }

        $assignment = Assignment::query()->updateOrCreate(
            ['assessment_id' => $assessment->id, 'learner_id' => $learner->id],
            ['assigned_by' => $tutor->id, 'due_at' => $dueAt],
        );

        return ['assignment_id' => $assignment->id, 'assessment_id' => $assessment->id, 'learner_id' => $learner->id, 'due_at' => $assignment->due_at?->toIso8601String()];
    }
}
