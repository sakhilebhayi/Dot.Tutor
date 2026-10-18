<?php

namespace App\Services;

use App\Models\SessionRating;
use App\Models\TutorSession;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class RatingService
{
    /**
     * Store the rating and refresh the tutor's average as one unit: if the
     * average cannot be written, the rating is not kept either. Returns false
     * when the session already has a rating (a double submit racing the
     * policy check), instead of surfacing a unique-constraint 500.
     */
    public function rate(TutorSession $session, User $rater, int $rating, ?string $review): bool
    {
        try {
            DB::transaction(function () use ($session, $rater, $rating, $review) {
                SessionRating::create([
                    'session_id' => $session->id,
                    'rated_by' => $rater->id,
                    'rating' => $rating,
                    'review' => $review,
                ]);

                $session->tutorProfile->refreshRating();
            });
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }
}
