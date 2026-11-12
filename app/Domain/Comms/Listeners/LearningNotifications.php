<?php

namespace App\Domain\Comms\Listeners;

use App\Domain\Comms\CommsMessage;
use App\Domain\Comms\Messenger;
use App\Domain\Learning\Enums\ResourceStatus;
use App\Domain\Learning\Models\Assignment;
use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\Enrolment;
use App\Domain\Learning\Models\LibraryResource;
use App\Domain\Learning\Models\ResourceReview;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Course enrolments, assignments and library decisions. Thin model observers on the learning domain's rows (run
 * after commit, read only): the learning domain raises no events of its own.
 */
final class LearningNotifications
{
    public bool $afterCommit = true;

    public function __construct(private readonly Messenger $m) {}

    public function enrolmentCreated(Enrolment $enrolment): void
    {
        try {
            $course = Course::query()->find($enrolment->course_id);
            $learner = User::find($enrolment->user_id);

            if ($course === null || $learner === null) {
                return;
            }

            $url = route('learning.courses.show', $course, false);
            // The learner enrolled themselves: an inbox entry only (course_enrolled is in-app only), and their guardians are told.
            $this->m->learnerAndGuardians($learner,
                new CommsMessage($learner, 'course_enrolled', 'enrolment:'.$enrolment->id, 'You are enrolled in '.$course->title,
                    'Start whenever you are ready; your place in each lesson is saved.', $url, 'course-'.$course->id, $learner->id,
                    data: ['course_id' => $course->id]),
                fn (User $g) => [$this->m->firstName($learner).' enrolled in a course', $this->m->firstName($learner).' enrolled in "'.$course->title.'".']);

            $owner = $course->owner_id ? User::find($course->owner_id) : null;
            if ($owner !== null && $owner->id !== $learner->id) {
                $count = Enrolment::query()->where('course_id', $course->id)->where('status', 'active')->count();
                // One unread notice per course, replaced as more learners join; learners are not named.
                $this->m->send(new CommsMessage($owner, 'course_new_learner', 'enrolment:'.$enrolment->id.':owner', 'New learner in '.$course->title,
                    'A learner enrolled. '.$count.' '.($count === 1 ? 'learner is' : 'learners are').' enrolled now.',
                    route('learning.studio.courses.learners', $course, false), 'course-learners-'.$course->id, $learner->id,
                    replaceUnread: true, data: ['course_id' => $course->id]));
            }
        } catch (Throwable $e) {
            Log::warning('Comms could not notify an enrolment.', ['enrolment_id' => $enrolment->id, 'error' => class_basename($e)]);
        }
    }

    public function assignmentSaved(Assignment $assignment): void
    {
        try {
            if (! $assignment->wasRecentlyCreated && ! $assignment->wasChanged('due_at')) {
                return;
            }

            $learner = User::find($assignment->learner_id);
            $assessment = $assignment->assessment;
            $tutor = $assignment->assigned_by ? User::find($assignment->assigned_by) : null;

            if ($learner === null || $assessment === null) {
                return;
            }

            $due = $assignment->due_at ? ' It is due '.$this->m->when($assignment->due_at, $learner).'.' : '';
            $this->m->send(new CommsMessage($learner, 'assignment_set',
                'assignment:'.$assignment->id.':set:'.($assignment->due_at?->timestamp ?? 0),
                $assignment->wasRecentlyCreated ? 'New work from '.($tutor?->name ?? 'your tutor') : 'Due date changed',
                '"'.$assessment->title.'" was '.($assignment->wasRecentlyCreated ? 'set for you' : 'given a new due date').'.'.$due,
                route('learning.assessments.show', $assessment, false), 'assignment-'.$assignment->id, $tutor?->id,
                data: ['assessment_id' => $assessment->id, 'action_label' => 'Open the work']));
        } catch (Throwable $e) {
            Log::warning('Comms could not notify an assignment.', ['assignment_id' => $assignment->id, 'error' => class_basename($e)]);
        }
    }

    /** Approvals come from a recorded review; rejections are reported as take-downs (one message, not two). */
    public function resourceReviewCreated(ResourceReview $review): void
    {
        try {
            if (! in_array($review->outcome, ['approved', 'source_confirmed'], true)) {
                return;
            }

            $resource = LibraryResource::query()->find($review->resource_id);
            $owner = $resource?->owner_id ? User::find($resource->owner_id) : null;

            if ($resource === null || $owner === null) {
                return;
            }

            $this->m->send(new CommsMessage($owner, 'resource_approved', 'resource-review:'.$review->id, 'Your resource passed review',
                '"'.$resource->title.'" was reviewed by '.$review->reviewer_name.($review->outcome === 'source_confirmed' ? ', who confirmed its official source.' : ' and now shows as reviewed.'),
                route('learning.library.show', $resource, false), 'resource-'.$resource->id, $review->reviewer_id, data: ['resource_id' => $resource->id]));
        } catch (Throwable $e) {
            Log::warning('Comms could not notify a resource review.', ['review_id' => $review->id, 'error' => class_basename($e)]);
        }
    }

    public function resourceUpdated(LibraryResource $resource): void
    {
        try {
            if (! $resource->wasChanged('status') || $resource->owner_id === null) {
                return;
            }

            $owner = User::find($resource->owner_id);
            $from = $resource->getPrevious()['status'] ?? null; // after commit the original is already synced
            $from = $from instanceof ResourceStatus ? $from : ResourceStatus::tryFrom((string) $from);
            $to = $resource->status;

            if ($owner === null) {
                return;
            }

            if ($to === ResourceStatus::TakenDown) {
                $this->m->send(new CommsMessage($owner, 'resource_taken_down', 'resource:'.$resource->id.':down:'.($resource->taken_down_at?->timestamp ?? now()->timestamp),
                    'Your resource was taken down', '"'.$resource->title.'" is hidden from the library. Reason: '.($resource->takedown_reason ?: 'not given').' If you hold the rights, you can answer a copyright notice from the resource page.',
                    route('learning.library.show', $resource, false), 'resource-'.$resource->id, null, data: ['resource_id' => $resource->id]));
            } elseif ($from === ResourceStatus::TakenDown && $to === ResourceStatus::Published) {
                $this->m->send(new CommsMessage($owner, 'resource_restored', 'resource:'.$resource->id.':restored:'.now()->timestamp,
                    'Your resource is back in the library', '"'.$resource->title.'" was restored after review.',
                    route('learning.library.show', $resource, false), 'resource-'.$resource->id, null, data: ['resource_id' => $resource->id]));
            }
        } catch (Throwable $e) {
            Log::warning('Comms could not notify a resource change.', ['resource_id' => $resource->id, 'error' => class_basename($e)]);
        }
    }
}
