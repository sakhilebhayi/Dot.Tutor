<?php

namespace App\Http\Controllers;

use App\Models\TutorSession;
use App\Services\RatingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SessionRatingController extends Controller
{
    /**
     * The student leaves a rating + optional review on a completed session
     * they booked. See TutorSessionPolicy::rate() for why only the student
     * (not the tutor) can do this.
     */
    public function store(Request $request, TutorSession $tutorSession, RatingService $ratings): RedirectResponse
    {
        Gate::authorize('rate', $tutorSession);

        $validated = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'review' => ['nullable', 'string', 'max:2000'],
        ]);

        $saved = $ratings->rate(
            $tutorSession,
            $request->user(),
            (int) $validated['rating'],
            $validated['review'] ?? null,
        );

        return redirect()
            ->route('sessions.show', $tutorSession)
            ->with('status', $saved ? 'Thanks for your review!' : 'This session has already been rated.');
    }
}
