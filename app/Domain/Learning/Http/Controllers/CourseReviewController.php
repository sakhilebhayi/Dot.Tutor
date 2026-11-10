<?php

namespace App\Domain\Learning\Http\Controllers;

use App\Domain\Learning\Contracts\Seams\SafeguardingCheck;
use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\CourseReview;
use App\Domain\Learning\Models\LessonProgress;
use App\Domain\Learning\Services\CourseAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Course reviews: only by learners enrolled in the course who have completed at least one lesson. */
class CourseReviewController extends LearningController
{
    public function index(Request $request, Course $course, CourseAccess $access): JsonResponse
    {
        abort_unless($access->canViewOutline($request->user(), $course), 404);

        return response()->json(['data' => $course->reviews()->with('user:id,name')->where('status', 'published')->latest('id')->limit(50)->get()
            ->map(fn (CourseReview $r) => [
                'rating' => $r->rating, 'body' => $r->body, 'learner' => $r->user?->name ?? 'Deleted account',
                'created_at' => $r->created_at->toIso8601String(), 'verified_enrolment' => true,
            ])]);
    }

    public function store(Request $request, Course $course, CourseAccess $access, SafeguardingCheck $safeguarding): JsonResponse|RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'body' => ['nullable', 'string', 'max:3000'],
        ]);
        if ($course->isOwnedBy($user)) {
            throw ValidationException::withMessages(['rating' => 'You cannot review your own course.']);
        }
        $enrolment = $access->activeEnrolment($user, $course);
        if ($enrolment === null) {
            return $this->wantsJson($request) ? $this->error('not_enrolled', 'Only learners enrolled in this course can review it.', 403) : abort(403);
        }
        if (! LessonProgress::query()->where('enrolment_id', $enrolment->id)->whereNotNull('completed_at')->exists()) {
            throw ValidationException::withMessages(['rating' => 'Complete at least one lesson before reviewing the course.']);
        }
        $decision = $safeguarding->allows($user, 'publish', $course);
        if (! $decision->allowed) {
            throw ValidationException::withMessages(['rating' => $decision->reason ?? 'Posting reviews is not allowed for this account.']);
        }
        $review = CourseReview::query()->updateOrCreate(
            ['course_id' => $course->id, 'user_id' => $user->id],
            ['rating' => $data['rating'], 'body' => $data['body'] ?? null, 'status' => 'published'],
        );

        return $this->wantsJson($request) ? response()->json(['data' => ['id' => $review->id, 'rating' => $review->rating]], 201) : back()->with('status', 'Thank you for your review.');
    }
}
