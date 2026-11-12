<?php

namespace App\Domain\Marketplace\Services;

use App\Domain\Marketplace\Enums\SeatStatus;
use App\Domain\Marketplace\Models\TutorBookingSeries;
use App\Domain\Marketplace\Models\TutorPackagePurchase;
use App\Domain\Marketplace\Models\TutorSessionEvent;
use App\Domain\Marketplace\Models\TutorSessionParticipant;
use App\Domain\Marketplace\Support\MinorUnits;
use App\Domain\Student\Support\Times;
use App\Enums\SessionStatus;
use App\Models\TutorSession;
use App\Models\User;

/** JSON shapes for bookings, seats, series and packages, plus the plain sentence for "what happens next". */
class BookingPresenter
{
    /** @return array<string, mixed> */
    public function session(TutorSession $s, ?User $viewer = null): array
    {
        $s->loadMissing(['tutorProfile.user', 'subject', 'offering']);
        $status = SessionStatus::from($s->status);
        $isTutor = $viewer !== null && $s->tutorProfile->user_id === $viewer->id;

        $data = [
            'id' => $s->id,
            'kind' => $s->kind,
            'state' => $status->state(),
            'state_label' => $status->label(),
            'next_step' => $this->nextStep($s),
            'tutor' => ['id' => $s->tutor_profile_id, 'name' => $s->tutorProfile->displayName()],
            'learner_id' => $s->student_id,
            'subject' => ['id' => $s->subject_id, 'name' => $s->subject?->name],
            'offering' => $s->offering ? ['id' => $s->offering->id, 'type' => $s->offering->type, 'title' => $s->offering->title] : null,
            'starts_at' => $s->starts_at->copy()->utc()->toIso8601String(),
            'ends_at' => $s->endsAt()->copy()->utc()->toIso8601String(),
            'duration_minutes' => $s->duration_minutes,
            'timezone' => $s->tutorProfile->scheduleTimezone(),
            'delivery' => $s->delivery,
            'location_note' => $s->location_note,
            'price' => [
                'amount_minor' => $s->price_minor ?? (int) round(((int) $s->rate_minor) * $s->duration_minutes / 60),
                'currency' => $s->currency,
                'display' => MinorUnits::format($s->price_minor ?? (int) round(((int) $s->rate_minor) * $s->duration_minutes / 60), $s->currency),
            ],
            'payment_status' => $s->payment_status,
            'guardian_status' => $s->guardian_status,
            'payment_hold_expires_at' => $s->hold_expires_at?->copy()->utc()->toIso8601String(),
            'series_id' => $s->tutor_booking_series_id,
            'package_purchase_id' => $s->tutor_package_purchase_id,
            'decision_reason' => $s->decision_reason,
            'cancellation' => $s->offering ? app(TutorPresenter::class)->cancellationSummary($s->offering) : null,
        ];

        if ($s->isGroup()) {
            $data['capacity'] = $s->capacity;
            $data['seats_taken'] = $s->seats_taken;
            $data['seats_left'] = max(0, (int) $s->capacity - (int) $s->seats_taken);
            if ($isTutor) {
                $data['participants'] = $s->participants()->get()->map(fn ($p) => $this->seat($p, false))->all();
            }
        }

        if ($viewer !== null && ($isTutor || $s->student_id === $viewer->id)) {
            $data['events'] = ($s->relationLoaded('events') ? $s->events : $s->events()->get())->map(fn (TutorSessionEvent $e) => [
                'event' => $e->event,
                'from' => $e->from_state ? SessionStatus::tryFrom($e->from_state)?->state() ?? $e->from_state : null,
                'to' => $e->to_state ? SessionStatus::tryFrom($e->to_state)?->state() ?? $e->to_state : null,
                'reason' => $e->reason,
                'at' => $e->created_at?->toIso8601String(),
                'participant_id' => $e->participant_id,
            ])->all();
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public function seat(TutorSessionParticipant $p, bool $withSession = true): array
    {
        return array_filter([
            'id' => $p->id,
            'session_id' => $p->tutor_session_id,
            'learner_id' => $p->learner_id,
            'state' => $p->status,
            'price' => ['amount_minor' => $p->price_minor, 'currency' => $p->currency, 'display' => MinorUnits::format($p->price_minor, $p->currency)],
            'payment_status' => $p->payment_status,
            'guardian_status' => $p->guardian_status,
            'payment_hold_expires_at' => $p->hold_expires_at?->copy()->utc()->toIso8601String(),
            'next_step' => match ($p->status) {
                SeatStatus::AwaitingGuardian->value => 'Waiting for a guardian to approve this seat.',
                SeatStatus::AwaitingPayment->value => 'Pay to keep this seat. It is held until '.Times::stamp($p->hold_expires_at, 'H:i').'.',
                SeatStatus::Confirmed->value => 'Your seat is confirmed.',
                default => null,
            },
            'session' => $withSession ? $this->session($p->session) : null,
        ], fn ($v) => $v !== null);
    }

    /** @return array<string, mixed> */
    public function package(TutorPackagePurchase $p): array
    {
        return [
            'id' => $p->id,
            'tutor_profile_id' => $p->tutor_profile_id,
            'offering_id' => $p->tutor_offering_id,
            'state' => $p->status,
            'lessons_total' => $p->lessons_total,
            'lessons_used' => $p->lessons_used,
            'lessons_left' => $p->creditsLeft(),
            'lesson_minutes' => $p->lesson_minutes,
            'price' => ['amount_minor' => $p->price_minor, 'currency' => $p->currency, 'display' => MinorUnits::format($p->price_minor, $p->currency)],
            'payment_status' => $p->payment_status,
            'guardian_status' => $p->guardian_status,
            'expires_at' => $p->expires_at?->copy()->utc()->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function series(TutorBookingSeries $series, ?User $viewer = null): array
    {
        return [
            'id' => $series->id,
            'tutor_profile_id' => $series->tutor_profile_id,
            'offering_id' => $series->tutor_offering_id,
            'weekday' => $series->weekday,
            'local_time' => $series->local_time,
            'timezone' => $series->timezone,
            'weeks' => $series->weeks,
            'state' => $series->status,
            'sessions' => $series->sessions->map(fn ($s) => $this->session($s, $viewer))->all(),
        ];
    }

    public function nextStep(TutorSession $s): string
    {
        return match (SessionStatus::from($s->status)) {
            SessionStatus::AwaitingGuardian => 'Waiting for a guardian to approve. The tutor sees the request after that.',
            SessionStatus::Pending => 'Waiting for the tutor to accept or decline.',
            SessionStatus::AwaitingPayment => 'Accepted by the tutor. Pay to confirm it'.($s->hold_expires_at ? ' by '.Times::stamp($s->hold_expires_at, 'D j M, H:i') : '').'.',
            SessionStatus::Confirmed => $s->isGroup() ? 'Scheduled. Learners can take seats until it is full.' : 'Confirmed.',
            SessionStatus::InProgress => 'The lesson is under way.',
            SessionStatus::Completed => 'Completed.',
            SessionStatus::Cancelled => 'Cancelled.',
            SessionStatus::NoShow => 'Recorded as a no-show.',
            SessionStatus::Disputed => 'Disputed. An operator will review it.',
        };
    }
}
