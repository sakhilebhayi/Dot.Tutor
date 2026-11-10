<?php

namespace App\Domain\Marketplace\Services;

use App\Domain\Marketplace\Contracts\AuditPort;
use App\Domain\Marketplace\Contracts\ReportSinkPort;
use App\Domain\Marketplace\Enums\ReviewStatus;
use App\Domain\Marketplace\Enums\SeatStatus;
use App\Domain\Marketplace\Exceptions\MarketplaceException;
use App\Domain\Marketplace\Models\ReviewReport;
use App\Enums\SessionStatus;
use App\Models\SessionRating;
use App\Models\TutorSession;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Reviews v2 (brief sections 6, 15, 18):
 *  - only from a completed booking, by the learner on it, once per booking
 *    (a group seat is its own booking);
 *  - a review that carries contact details (email, phone, link) is held for
 *    moderation on arrival: off-platform contact is a safeguarding risk;
 *  - reports go to the safety moderation queue (ReportSinkPort); three
 *    distinct reporters hold the review until an operator decides;
 *  - the tutor may publish one reply (editable);
 *  - only published reviews are public and count toward the average.
 */
class ReviewService
{
    public const REPORTS_TO_HOLD = 3;

    public function __construct(private ReportSinkPort $reports, private AuditPort $audit) {}

    public function review(TutorSession $session, User $learner, int $rating, ?string $text): SessionRating
    {
        if (! $this->canReview($session, $learner)) {
            throw new MarketplaceException('not_reviewable', 'Only a learner on a completed lesson can review it, once.', 403);
        }
        $heldReason = $this->screen($text);

        try {
            return DB::transaction(function () use ($session, $learner, $rating, $text, $heldReason) {
                $review = SessionRating::create([
                    'session_id' => $session->id,
                    'rated_by' => $learner->id,
                    'rating' => $rating,
                    'review' => $text,
                ]);
                $review->forceFill([
                    'status' => $heldReason ? ReviewStatus::Held->value : ReviewStatus::Published->value,
                    'held_reason' => $heldReason,
                ])->save();

                $session->tutorProfile->refreshRating();

                if ($heldReason !== null) {
                    $this->reports->report($review, null, $heldReason, ['source' => 'automatic_screen']);
                }

                return $review;
            });
        } catch (UniqueConstraintViolationException) {
            throw new MarketplaceException('already_reviewed', 'You have already reviewed this lesson.', 409);
        }
    }

    public function canReview(TutorSession $session, User $learner): bool
    {
        if ($session->status !== SessionStatus::Completed->value) {
            return false;
        }
        $onBooking = $session->isGroup()
            ? $session->participants()->where('learner_id', $learner->id)->where('status', SeatStatus::Attended->value)->exists()
            : $session->student_id === $learner->id;

        return $onBooking && ! $session->reviews()->where('rated_by', $learner->id)->exists();
    }

    public function reply(SessionRating $review, User $tutor, string $reply): SessionRating
    {
        $session = $review->session;
        if ($session->tutorProfile->user_id !== $tutor->id) {
            throw new MarketplaceException('forbidden', 'Only the tutor who was reviewed can reply.', 403);
        }
        if ($review->status !== ReviewStatus::Published->value) {
            throw new MarketplaceException('not_published', 'You can reply once the review is published.', 409);
        }
        if ($this->screen($reply) !== null) {
            throw new MarketplaceException('contact_details', 'Replies cannot include email addresses, phone numbers or links.', 422);
        }

        $review->forceFill(['tutor_reply' => $reply, 'tutor_replied_at' => now()])->save();

        return $review;
    }

    public function report(SessionRating $review, User $reporter, string $reason): SessionRating
    {
        return DB::transaction(function () use ($review, $reporter, $reason) {
            $locked = SessionRating::query()->whereKey($review->id)->lockForUpdate()->firstOrFail();
            if ($locked->rated_by === $reporter->id) {
                throw new MarketplaceException('own_review', 'You cannot report your own review.', 422);
            }

            try {
                $report = ReviewReport::create(['session_rating_id' => $locked->id, 'reporter_id' => $reporter->id, 'reason' => $reason]);
            } catch (UniqueConstraintViolationException) {
                throw new MarketplaceException('already_reported', 'You have already reported this review.', 409);
            }
            $report->update(['sink_reference' => $this->reports->report($locked, $reporter, $reason, ['source' => 'user_report'])]);

            if ($locked->status === ReviewStatus::Published->value
                && ReviewReport::query()->where('session_rating_id', $locked->id)->distinct()->count('reporter_id') >= self::REPORTS_TO_HOLD) {
                $locked->forceFill(['status' => ReviewStatus::Held->value, 'held_reason' => 'Reported by several people; waiting for a moderator.'])->save();
                $locked->session->tutorProfile->refreshRating();
            }

            return $locked;
        });
    }

    /** An operator publishes or removes a held (or published) review. */
    public function moderate(SessionRating $review, User $operator, ReviewStatus $to, string $reason): SessionRating
    {
        if ($to === ReviewStatus::Held) {
            throw new MarketplaceException('invalid', 'Choose publish or remove.', 422);
        }
        if ($review->session->tutorProfile->user_id === $operator->id || $review->rated_by === $operator->id) {
            throw new MarketplaceException('conflict_of_interest', 'Operators cannot moderate reviews they wrote or received.', 403);
        }

        return DB::transaction(function () use ($review, $operator, $to, $reason) {
            $from = $review->status;
            $review->forceFill([
                'status' => $to->value,
                'held_reason' => $to === ReviewStatus::Published ? null : $reason,
                'moderated_by' => $operator->id,
                'moderated_at' => now(),
            ])->save();
            $review->session->tutorProfile->refreshRating();
            $this->audit->record($operator, 'marketplace.review.moderate', 'session_rating', $review->id, ['from' => $from, 'to' => $to->value, 'reason' => $reason]);

            return $review;
        });
    }

    /** Why a text must be held, or null. Contact details pull people off the platform, away from safeguarding. */
    public function screen(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }
        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text)) {
            return 'Contains an email address.';
        }
        if (preg_match('/(https?:\/\/|www\.)\S+/i', $text)) {
            return 'Contains a link.';
        }
        if (preg_match('/(\+?\d[\d\s\-()]{8,}\d)/', $text)) {
            return 'Contains a phone number.';
        }

        return null;
    }
}
