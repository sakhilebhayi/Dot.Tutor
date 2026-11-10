<?php

namespace App\Domain\Marketplace\Http\Controllers\Api;

use App\Domain\Marketplace\Services\ReviewService;
use App\Http\Controllers\Controller;
use App\Models\SessionRating;
use App\Models\TutorSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function store(Request $request, TutorSession $tutorSession, ReviewService $reviews): JsonResponse
    {
        $data = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'review' => ['nullable', 'string', 'max:2000'],
        ]);
        $review = $reviews->review($tutorSession, $request->user(), (int) $data['rating'], $data['review'] ?? null);

        return response()->json(['data' => $this->shape($review), 'notice' => $review->status === 'held'
            ? 'Thanks. Your review is held for a moderator because '.lcfirst((string) $review->held_reason).' It is not public yet.'
            : 'Thanks. Your review is published.'], 201);
    }

    public function reply(Request $request, SessionRating $review, ReviewService $reviews): JsonResponse
    {
        $data = $request->validate(['reply' => ['required', 'string', 'min:2', 'max:2000']]);

        return response()->json(['data' => $this->shape($reviews->reply($review, $request->user(), $data['reply']))]);
    }

    public function report(Request $request, SessionRating $review, ReviewService $reviews): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $reviews->report($review, $request->user(), $data['reason']);

        return response()->json(['data' => ['reported' => true], 'notice' => 'Thanks. A moderator will look at this review.'], 201);
    }

    /** @return array<string, mixed> */
    private function shape(SessionRating $r): array
    {
        return [
            'id' => $r->id,
            'session_id' => $r->session_id,
            'rating' => $r->rating,
            'review' => $r->review,
            'status' => $r->status,
            'held_reason' => $r->held_reason,
            'tutor_reply' => $r->tutor_reply,
            'tutor_replied_at' => $r->tutor_replied_at?->toIso8601String(),
        ];
    }
}
