<?php

namespace App\Services;

use App\Domain\Marketplace\Contracts\ReportSinkPort;
use App\Domain\Marketplace\Enums\ReviewStatus;
use App\Domain\Marketplace\Services\ReviewService;
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
                $created = SessionRating::create([
                    'session_id' => $session->id,
                    'rated_by' => $rater->id,
                    'rating' => $rating,
                    'review' => $review,
                ]);

                // R-47: the same screen as the v2 review API. A review carrying contact
                // details is held for moderation and does not count until published.
                $held = app(ReviewService::class)->screen($review);
                if ($held !== null) {
                    $created->forceFill(['status' => ReviewStatus::Held->value, 'held_reason' => $held])->save();
                    app(ReportSinkPort::class)->report($created, null, $held, ['source' => 'automatic_screen']);
                }

                $session->tutorProfile->refreshRating();
            });
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }
}
