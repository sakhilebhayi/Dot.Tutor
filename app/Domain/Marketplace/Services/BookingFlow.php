<?php

namespace App\Domain\Marketplace\Services;

use App\Domain\Marketplace\Contracts\GuardianApprovalsPort;
use App\Domain\Marketplace\Contracts\SafeguardingPort;
use App\Domain\Marketplace\Enums\OfferingType;
use App\Domain\Marketplace\Enums\SeatStatus;
use App\Domain\Marketplace\Enums\TeachingFormat;
use App\Domain\Marketplace\Exceptions\MarketplaceException;
use App\Domain\Marketplace\Models\TutorBookingSeries;
use App\Domain\Marketplace\Models\TutorOffering;
use App\Domain\Marketplace\Models\TutorPackagePurchase;
use App\Domain\Marketplace\Models\TutorSessionParticipant;
use App\Enums\SessionStatus;
use App\Models\Subject;
use App\Models\TutorProfile;
use App\Models\TutorSession;
use App\Models\User;
use App\Services\Scheduling\BookingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Booking v2 (brief sections 6, 7 and 18): one-to-one lessons and the help
 * types delivered like them, group seats, recurring weekly series and lesson
 * packages, through one state machine.
 *
 * Locks are always taken in the same order (learner user row, tutor profile
 * row, then session row) so two bookings cannot deadlock. Group capacity is
 * guarded by a conditional UPDATE (seats_taken < capacity), which holds even
 * if the reader's view of the session is stale.
 *
 * Seams: SafeguardingPort (may this learner book at all), GuardianApprovalsPort
 * (minors wait as awaiting_guardian; the null default never approves) and,
 * through PaymentService, CheckoutPort (the null default leaves bookings
 * awaiting_payment and says payments are not configured).
 */
class BookingFlow
{
    public function __construct(
        private BookingService $scheduling,
        private BookingStateMachine $machine,
        private SafeguardingPort $safeguarding,
        private GuardianApprovalsPort $guardians,
    ) {}

    // ------------------------------------------------------------------
    // Creating bookings
    // ------------------------------------------------------------------

    /**
     * A learner requests a one-to-one style lesson (one_to_one, consultation,
     * homework_help, exam_prep, revision), or books one lesson from a package
     * they hold (package_purchase_id).
     *
     * @param  array{subject_id: int, starts_at: string, delivery?: ?string, notes?: ?string, location_note?: ?string, package_purchase_id?: ?int}  $data
     * @return array{0: TutorSession, 1: ?string} [session, notice for the learner]
     */
    public function requestBooking(User $learner, TutorOffering $offering, array $data): array
    {
        $type = $offering->typeEnum();
        if (in_array($type, [OfferingType::Group, OfferingType::RecurringWeekly], true)) {
            throw new MarketplaceException('wrong_offering', $type === OfferingType::Group
                ? 'Group lessons are booked by taking a seat on a scheduled lesson.'
                : 'Weekly lessons are booked as a series.', 422);
        }
        if ($type === OfferingType::Package && empty($data['package_purchase_id'])) {
            throw new MarketplaceException('package_required', 'Buy the package first, then book its lessons one at a time.', 422);
        }

        $notice = null;
        $session = DB::transaction(function () use ($learner, $offering, $data, &$notice) {
            User::query()->whereKey($learner->id)->lockForUpdate()->first();
            $profile = $this->lockBookableTutor($offering, $learner);
            $this->assertSafe($learner, 'book', $offering);

            $subject = $this->subjectFor($offering, (int) $data['subject_id']);
            $delivery = $this->deliveryFor($offering, $data['delivery'] ?? null);

            $purchase = null;
            $duration = $offering->duration_minutes;
            if (! empty($data['package_purchase_id'])) {
                $purchase = $this->lockUsablePackage((int) $data['package_purchase_id'], $learner, $profile);
                $duration = $purchase->lesson_minutes;
            }

            $start = $this->parseStart($data['starts_at'], $profile);
            $this->scheduling->assertSlotFree($profile, $learner, $start, $duration);

            $guardian = $this->guardians->requirement($learner, 'booking', $offering);
            if ($guardian === GuardianApprovalsPort::DECLINED) {
                throw new MarketplaceException('guardian_declined', 'A guardian has declined bookings like this one.', 403);
            }
            $held = $guardian === GuardianApprovalsPort::PENDING;

            [$price, $paymentStatus] = $purchase !== null
                ? [0, 'covered_by_package']
                : [$offering->price_minor, $offering->price_minor > 0 ? 'unpaid' : 'not_required'];

            $session = TutorSession::create([
                'tutor_profile_id' => $profile->id,
                'student_id' => $learner->id,
                'subject_id' => $subject->id,
                'tutor_offering_id' => $purchase?->tutor_offering_id ?? $offering->id,
                'tutor_package_purchase_id' => $purchase?->id,
                'kind' => TutorSession::KIND_ONE_TO_ONE,
                'status' => $held ? SessionStatus::AwaitingGuardian->value : SessionStatus::Pending->value,
                'guardian_status' => $held ? GuardianApprovalsPort::PENDING : ($guardian === GuardianApprovalsPort::APPROVED ? GuardianApprovalsPort::APPROVED : GuardianApprovalsPort::NOT_REQUIRED),
                'delivery' => $delivery,
                'location_note' => $data['location_note'] ?? null,
                'starts_at' => $start,
                'duration_minutes' => $duration,
                'price_minor' => $price,
                'rate_minor' => (int) round(($purchase ? intdiv($purchase->price_minor, max(1, $purchase->lessons_total)) : $offering->price_minor) * 60 / max(1, $duration)),
                'currency' => $purchase?->currency ?? $offering->currency,
                'payment_status' => $paymentStatus,
                'notes' => $data['notes'] ?? null,
            ]);

            if ($purchase !== null) {
                $purchase->increment('lessons_used');
                if ($purchase->creditsLeft() === 0) {
                    $purchase->update(['status' => TutorPackagePurchase::EXHAUSTED]);
                }
            }

            $this->machine->record($session, $held ? 'held_for_guardian' : 'requested', $learner, null, $session->status, null, array_filter([
                'offering_id' => $offering->id,
                'package_purchase_id' => $purchase?->id,
                'price_minor' => $price,
                'currency' => $session->currency,
            ], fn ($v) => $v !== null));

            if ($held) {
                $notice = $this->guardians->request($learner, 'booking', $session);
            }

            return $session;
        });

        return [$session, $notice];
    }

    /**
     * Weekly lessons: book the same local time each week for n weeks. Weeks
     * that cannot be booked (blocked date, taken, outside hours) are skipped
     * and reported; at least one must succeed.
     *
     * @param  array{subject_id: int, starts_at: string, weeks?: ?int, delivery?: ?string, notes?: ?string, location_note?: ?string}  $data
     * @return array{0: TutorBookingSeries, 1: list<array{starts_at: string, reason: string}>, 2: ?string}
     */
    public function bookSeries(User $learner, TutorOffering $offering, array $data): array
    {
        if ($offering->typeEnum() !== OfferingType::RecurringWeekly) {
            throw new MarketplaceException('wrong_offering', 'Only a weekly-lessons offering can be booked as a series.', 422);
        }

        $notice = null;
        $skipped = [];
        $series = DB::transaction(function () use ($learner, $offering, $data, &$skipped, &$notice) {
            User::query()->whereKey($learner->id)->lockForUpdate()->first();
            $profile = $this->lockBookableTutor($offering, $learner);
            $this->assertSafe($learner, 'book', $offering);
            $subject = $this->subjectFor($offering, (int) $data['subject_id']);
            $delivery = $this->deliveryFor($offering, $data['delivery'] ?? null);

            $weeks = min((int) ($data['weeks'] ?? $offering->recurring_weeks), (int) $offering->recurring_weeks);
            $first = $this->parseStart($data['starts_at'], $profile);
            $tz = $profile->scheduleTimezone();
            $localFirst = CarbonImmutable::createFromTimestamp($first->getTimestamp(), $tz);

            $guardian = $this->guardians->requirement($learner, 'booking', $offering);
            if ($guardian === GuardianApprovalsPort::DECLINED) {
                throw new MarketplaceException('guardian_declined', 'A guardian has declined bookings like this one.', 403);
            }
            $held = $guardian === GuardianApprovalsPort::PENDING;

            $series = TutorBookingSeries::create([
                'tutor_profile_id' => $profile->id,
                'learner_id' => $learner->id,
                'tutor_offering_id' => $offering->id,
                'subject_id' => $subject->id,
                'weekday' => $localFirst->isoWeekday(),
                'local_time' => $localFirst->format('H:i'),
                'timezone' => $tz,
                'weeks' => $weeks,
                'status' => 'active',
            ]);

            $booked = 0;
            for ($week = 0; $week < $weeks; $week++) {
                // Same local wall-clock time each week, so a DST change keeps the lesson at 16:00, not 15:00.
                $date = CarbonImmutable::parse($localFirst->toDateString(), 'UTC')->addWeeks($week)->toDateString();
                $start = Carbon::createFromFormat('!Y-m-d H:i', $date.' '.$localFirst->format('H:i'), $tz)->utc();
                try {
                    $this->scheduling->assertSlotFree($profile, $learner, $start, $offering->duration_minutes);
                } catch (ValidationException $e) {
                    $skipped[] = ['starts_at' => $start->toIso8601String(), 'reason' => collect($e->errors())->flatten()->first()];

                    continue;
                }

                $session = TutorSession::create([
                    'tutor_profile_id' => $profile->id,
                    'student_id' => $learner->id,
                    'subject_id' => $subject->id,
                    'tutor_offering_id' => $offering->id,
                    'tutor_booking_series_id' => $series->id,
                    'kind' => TutorSession::KIND_ONE_TO_ONE,
                    'status' => $held ? SessionStatus::AwaitingGuardian->value : SessionStatus::Pending->value,
                    'guardian_status' => $held ? GuardianApprovalsPort::PENDING : GuardianApprovalsPort::NOT_REQUIRED,
                    'delivery' => $delivery,
                    'location_note' => $data['location_note'] ?? null,
                    'starts_at' => $start,
                    'duration_minutes' => $offering->duration_minutes,
                    'price_minor' => $offering->price_minor,
                    'rate_minor' => (int) round($offering->price_minor * 60 / max(1, $offering->duration_minutes)),
                    'currency' => $offering->currency,
                    'payment_status' => $offering->price_minor > 0 ? 'unpaid' : 'not_required',
                    'notes' => $data['notes'] ?? null,
                ]);
                $this->machine->record($session, $held ? 'held_for_guardian' : 'requested', $learner, null, $session->status, null, ['series_id' => $series->id, 'week' => $week + 1]);
                $booked++;
            }

            if ($booked === 0) {
                throw ValidationException::withMessages(['starts_at' => 'None of those weeks are open. Pick another time.']);
            }
            if ($held) {
                $notice = $this->guardians->request($learner, 'booking', $series);
            }

            return $series;
        });

        return [$series->load('sessions'), $skipped, $notice];
    }

    /**
     * The tutor puts a group lesson on the calendar. It holds the tutor's time
     * at once; learners then take seats until it is full.
     *
     * @param  array{subject_id: int, starts_at: string, location_note?: ?string, notes?: ?string}  $data
     */
    public function scheduleGroup(User $tutor, TutorOffering $offering, array $data): TutorSession
    {
        if (! $offering->typeEnum()->isGroup()) {
            throw new MarketplaceException('wrong_offering', 'Only a group-lesson offering can be scheduled as a group.', 422);
        }

        return DB::transaction(function () use ($tutor, $offering, $data) {
            $profile = TutorProfile::query()->whereKey($offering->tutor_profile_id)->lockForUpdate()->firstOrFail();
            if ($profile->user_id !== $tutor->id) {
                throw new MarketplaceException('forbidden', 'Only the tutor can schedule their group lessons.', 403);
            }
            if (! $profile->isApproved() || ! $offering->is_active) {
                throw new MarketplaceException('not_bookable', 'Group lessons can be scheduled once your profile is approved and the offering is active.', 409);
            }
            $this->assertSafe($tutor, 'publish', $offering);
            $subject = $this->subjectFor($offering, (int) $data['subject_id']);
            $start = $this->parseStart($data['starts_at'], $profile);
            $this->scheduling->assertSlotFree($profile, null, $start, $offering->duration_minutes);

            $session = TutorSession::create([
                'tutor_profile_id' => $profile->id,
                'student_id' => null,
                'subject_id' => $subject->id,
                'tutor_offering_id' => $offering->id,
                'kind' => TutorSession::KIND_GROUP,
                'capacity' => $offering->capacity,
                'seats_taken' => 0,
                'status' => SessionStatus::Confirmed->value,
                'delivery' => $offering->format === TeachingFormat::InPerson->value ? 'in_person' : 'online',
                'location_note' => $data['location_note'] ?? null,
                'starts_at' => $start,
                'duration_minutes' => $offering->duration_minutes,
                'price_minor' => $offering->price_minor,
                'rate_minor' => (int) round($offering->price_minor * 60 / max(1, $offering->duration_minutes)),
                'currency' => $offering->currency,
                'payment_status' => 'not_required',
                'notes' => $data['notes'] ?? null,
            ]);
            $this->machine->record($session, 'scheduled', $tutor, null, $session->status, null, ['capacity' => $offering->capacity, 'price_minor' => $offering->price_minor]);

            return $session;
        });
    }

    /**
     * A learner takes a seat on a scheduled group lesson.
     *
     * @return array{0: TutorSessionParticipant, 1: ?string}
     */
    public function requestSeat(User $learner, TutorSession $session): array
    {
        $notice = null;
        $seat = DB::transaction(function () use ($learner, $session, &$notice) {
            User::query()->whereKey($learner->id)->lockForUpdate()->first();
            $locked = TutorSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isGroup() || $locked->status !== SessionStatus::Confirmed->value || $locked->starts_at->isPast()) {
                throw new MarketplaceException('not_open', 'This group lesson is not taking seats.', 409);
            }
            $profile = $locked->tutorProfile;
            if (! $profile->isApproved()) {
                throw new MarketplaceException('not_open', 'This group lesson is not taking seats.', 409);
            }
            if ($profile->user_id === $learner->id) {
                throw new MarketplaceException('forbidden', 'You cannot take a seat on your own lesson.', 403);
            }
            $this->assertSafe($learner, 'book', $locked);

            $existing = TutorSessionParticipant::query()
                ->where('tutor_session_id', $locked->id)->where('learner_id', $learner->id)->first();
            if ($existing !== null && in_array($existing->status, SeatStatus::holding(), true)) {
                throw new MarketplaceException('already_booked', 'You already have a seat on this lesson.', 409);
            }
            if ($this->scheduling->learnerBusy($learner, $locked->starts_at->copy(), $locked->endsAt()->copy())) {
                throw ValidationException::withMessages(['starts_at' => 'You already have a session at that time.']);
            }

            $guardian = $this->guardians->requirement($learner, 'seat', $locked);
            if ($guardian === GuardianApprovalsPort::DECLINED) {
                throw new MarketplaceException('guardian_declined', 'A guardian has declined bookings like this one.', 403);
            }
            $held = $guardian === GuardianApprovalsPort::PENDING;

            $this->releaseExpiredSeatHolds($locked);
            if (! $this->takeSeat($locked)) {
                throw new MarketplaceException('full', 'That group lesson is full.', 409);
            }

            $price = (int) $locked->price_minor;
            $status = match (true) {
                $held => SeatStatus::AwaitingGuardian,
                $price > 0 => SeatStatus::AwaitingPayment,
                default => SeatStatus::Confirmed,
            };
            $attributes = [
                'status' => $status->value,
                'price_minor' => $price,
                'currency' => $locked->currency,
                'payment_status' => $price > 0 ? 'unpaid' : 'not_required',
                'guardian_status' => $held ? GuardianApprovalsPort::PENDING : GuardianApprovalsPort::NOT_REQUIRED,
                'hold_expires_at' => $status === SeatStatus::AwaitingPayment ? now()->addMinutes((int) config('marketplace.payment_hold_minutes')) : null,
                'cancelled_at' => null,
                'order_reference' => null,
            ];
            $seat = $existing !== null
                ? tap($existing)->update($attributes)
                : TutorSessionParticipant::create([...$attributes, 'tutor_session_id' => $locked->id, 'learner_id' => $learner->id]);

            $this->machine->record($locked, 'seat_requested', $learner, null, $seat->status, null, ['price_minor' => $price], $seat->id);
            if ($held) {
                $notice = $this->guardians->request($learner, 'seat', $seat);
            }

            return $seat;
        });

        return [$seat, $notice];
    }

    /** @return array{0: TutorPackagePurchase, 1: ?string} */
    public function buyPackage(User $learner, TutorOffering $offering): array
    {
        if ($offering->typeEnum() !== OfferingType::Package) {
            throw new MarketplaceException('wrong_offering', 'That offering is not a lesson package.', 422);
        }

        $notice = null;
        $purchase = DB::transaction(function () use ($learner, $offering, &$notice) {
            User::query()->whereKey($learner->id)->lockForUpdate()->first();
            $profile = $this->lockBookableTutor($offering, $learner);
            $this->assertSafe($learner, 'pay', $offering);

            $guardian = $this->guardians->requirement($learner, 'package', $offering);
            if ($guardian === GuardianApprovalsPort::DECLINED) {
                throw new MarketplaceException('guardian_declined', 'A guardian has declined purchases like this one.', 403);
            }
            $held = $guardian === GuardianApprovalsPort::PENDING;
            $free = $offering->price_minor === 0;

            $purchase = TutorPackagePurchase::create([
                'tutor_profile_id' => $profile->id,
                'learner_id' => $learner->id,
                'tutor_offering_id' => $offering->id,
                'lessons_total' => $offering->package_lessons,
                'lessons_used' => 0,
                'lesson_minutes' => $offering->duration_minutes,
                'price_minor' => $offering->price_minor,
                'currency' => $offering->currency,
                'status' => match (true) {
                    $held => TutorPackagePurchase::AWAITING_GUARDIAN,
                    $free => TutorPackagePurchase::ACTIVE,
                    default => TutorPackagePurchase::AWAITING_PAYMENT,
                },
                'payment_status' => $free ? 'not_required' : 'unpaid',
                'guardian_status' => $held ? GuardianApprovalsPort::PENDING : GuardianApprovalsPort::NOT_REQUIRED,
                'expires_at' => ($free && ! $held && $offering->package_valid_days) ? now()->addDays($offering->package_valid_days) : null,
            ]);
            if ($held) {
                $notice = $this->guardians->request($learner, 'package', $purchase);
            }

            return $purchase;
        });

        return [$purchase, $notice];
    }

    // ------------------------------------------------------------------
    // Tutor decisions
    // ------------------------------------------------------------------

    /** The tutor accepts a request. Paid bookings then wait for payment; free or package-covered ones are confirmed. */
    public function accept(TutorSession $session, User $tutor, ?string $note = null): TutorSession
    {
        $this->assertTutor($session, $tutor);
        $needsPayment = $session->payment_status === 'unpaid' && (int) $session->price_minor > 0;
        $to = $needsPayment ? SessionStatus::AwaitingPayment : SessionStatus::Confirmed;

        return $this->machine->transition($session, $to, $tutor, 'accepted', $note, $needsPayment ? [
            'hold_expires_at' => $this->paymentDeadline($session),
        ] : [], guard: function (TutorSession $locked) {
            if (! $locked->tutorProfile->isApproved()) {
                throw new MarketplaceException('not_approved', 'Your tutor profile is not approved, so you cannot accept bookings.', 403);
            }
        });
    }

    public function decline(TutorSession $session, User $tutor, string $reason): TutorSession
    {
        $this->assertTutor($session, $tutor);

        return $this->machine->transition($session, SessionStatus::Cancelled, $tutor, 'declined', $reason, [
            'decision_reason' => $reason,
            'cancelled_by' => $tutor->id,
            'cancelled_at' => now(),
        ], after: fn (TutorSession $locked) => $this->returnPackageCredit($locked));
    }

    /**
     * Learner or tutor cancels. The cancellation policy of the offering says
     * how much of a paid booking is due back; the figure is recorded on the
     * event for the money domain (it does the refund).
     */
    public function cancel(TutorSession $session, User $actor, ?string $reason = null): TutorSession
    {
        $isTutor = $session->tutorProfile->user_id === $actor->id;
        $isLearner = $session->student_id !== null && $session->student_id === $actor->id;
        if (! $isTutor && ! $isLearner) {
            throw new MarketplaceException('forbidden', 'Only the learner or the tutor can cancel this booking.', 403);
        }
        if ($session->isGroup() && ! $isTutor) {
            throw new MarketplaceException('forbidden', 'Cancel your seat instead; only the tutor can cancel the whole group lesson.', 403);
        }

        $policy = $this->refundDue($session, $isTutor);

        return $this->machine->transition($session, SessionStatus::Cancelled, $actor, 'cancelled', $reason, [
            'decision_reason' => $reason,
            'cancelled_by' => $actor->id,
            'cancelled_at' => now(),
        ], [
            'by' => $isTutor ? 'tutor' : 'learner',
            'refund_due_percent' => $policy['percent'],
            'refund_due_minor' => $policy['minor'],
            'policy' => $policy['rule'],
        ], after: function (TutorSession $locked) use ($policy, $actor) {
            if ($policy['percent'] === 100 || $locked->payment_status !== 'paid') {
                $this->returnPackageCredit($locked);
            }
            if ($locked->isGroup()) {
                $this->cancelAllSeats($locked, $actor, 'The tutor cancelled the group lesson.');
            }
        });
    }

    public function cancelSeat(TutorSessionParticipant $seat, User $actor, ?string $reason = null): TutorSessionParticipant
    {
        return DB::transaction(function () use ($seat, $actor, $reason) {
            $session = TutorSession::query()->whereKey($seat->tutor_session_id)->lockForUpdate()->firstOrFail();
            $locked = TutorSessionParticipant::query()->whereKey($seat->id)->lockForUpdate()->firstOrFail();
            $isTutor = $session->tutorProfile->user_id === $actor->id;
            if ($locked->learner_id !== $actor->id && ! $isTutor) {
                throw new MarketplaceException('forbidden', 'Only the learner or the tutor can cancel this seat.', 403);
            }
            if (! in_array($locked->status, SeatStatus::holding(), true)) {
                throw new MarketplaceException('invalid_transition', 'This seat is not active.', 409);
            }

            $policy = $this->refundDue($session, $isTutor, $locked->payment_status === 'paid' ? (int) $locked->price_minor : 0);
            $from = $locked->status;
            $locked->update(['status' => SeatStatus::Cancelled->value, 'cancelled_at' => now(), 'hold_expires_at' => null]);
            $this->releaseSeat($session);
            $this->machine->record($session, 'seat_cancelled', $actor, $from, SeatStatus::Cancelled->value, $reason, [
                'by' => $isTutor ? 'tutor' : 'learner',
                'refund_due_percent' => $policy['percent'],
                'refund_due_minor' => $policy['minor'],
            ], $locked->id);

            return $locked;
        });
    }

    /** Accept every request in a weekly series at once. */
    public function acceptSeries(TutorBookingSeries $series, User $tutor): int
    {
        // Another tutor's series is refused up front, not only lesson by lesson (R-47 security route sweep).
        if (TutorProfile::query()->whereKey($series->tutor_profile_id)->value('user_id') !== $tutor->id) {
            throw new MarketplaceException('forbidden', 'Only the tutor can do this.', 403);
        }

        $accepted = 0;
        foreach ($series->sessions()->where('status', SessionStatus::Pending->value)->get() as $session) {
            $this->accept($session, $tutor);
            $accepted++;
        }

        return $accepted;
    }

    // ------------------------------------------------------------------
    // Lesson time
    // ------------------------------------------------------------------

    public function start(TutorSession $session, User $tutor): TutorSession
    {
        $this->assertTutor($session, $tutor);

        return $this->machine->transition($session, SessionStatus::InProgress, $tutor, 'started', changes: ['started_at' => now()], guard: function (TutorSession $locked) {
            if (now()->lt($locked->starts_at->copy()->subMinutes(15)) || now()->gte($locked->endsAt())) {
                throw new MarketplaceException('not_now', 'A lesson can be started from 15 minutes before its start time until it ends.', 409);
            }
        });
    }

    /**
     * Mark the lesson as having happened. Returns false when it was already
     * completed (double click, both parties at once) so it is counted once.
     */
    public function complete(TutorSession $session, User $actor): bool
    {
        $this->assertParty($session, $actor);

        try {
            $this->machine->transition($session, SessionStatus::Completed, $actor, 'completed', changes: ['ended_at' => now()], guard: function (TutorSession $locked) {
                if (now()->lt($locked->starts_at)) {
                    throw new MarketplaceException('not_now', 'A lesson can only be completed once it has started.', 409);
                }
                if (! $locked->tutorProfile->isApproved()) {
                    throw new MarketplaceException('not_approved', 'This tutor is not approved, so the lesson cannot be completed.', 409);
                }
            }, after: function (TutorSession $locked) {
                $locked->tutorProfile->increment('total_sessions');
                if ($locked->isGroup()) {
                    $locked->participants()->where('status', SeatStatus::Confirmed->value)->update(['status' => SeatStatus::Attended->value]);
                }
            });
        } catch (MarketplaceException $e) {
            if ($e->error === 'invalid_transition' && TutorSession::query()->whereKey($session->id)->value('status') === SessionStatus::Completed->value) {
                return false;
            }
            throw $e;
        }

        return true;
    }

    public function markNoShow(TutorSession $session, User $tutor, ?string $reason = null): TutorSession
    {
        $this->assertTutor($session, $tutor);

        return $this->machine->transition($session, SessionStatus::NoShow, $tutor, 'no_show', $reason, guard: function (TutorSession $locked) {
            if (now()->lt($locked->starts_at->copy()->addMinutes(15))) {
                throw new MarketplaceException('not_now', 'A no-show can be recorded from 15 minutes after the start time.', 409);
            }
        });
    }

    public function dispute(TutorSession $session, User $actor, string $reason): TutorSession
    {
        $this->assertParty($session, $actor);

        return $this->machine->transition($session, SessionStatus::Disputed, $actor, 'disputed', $reason, guard: function (TutorSession $locked) {
            $deadline = $locked->endsAt()->copy()->addDays((int) config('marketplace.dispute_window_days'));
            if (now()->gt($deadline)) {
                throw new MarketplaceException('too_late', 'The time to dispute this lesson has passed.', 409);
            }
        });
    }

    /** An operator settles a dispute: the lesson stands (completed) or is void (cancelled, with the refund due). */
    public function resolveDispute(TutorSession $session, User $operator, string $outcome, int $refundPercent, string $reason): TutorSession
    {
        $to = $outcome === 'completed' ? SessionStatus::Completed : SessionStatus::Cancelled;

        return $this->machine->transition($session, $to, $operator, 'dispute_resolved', $reason, [], [
            'outcome' => $outcome,
            'refund_due_percent' => $refundPercent,
            'refund_due_minor' => intdiv((int) $session->price_minor * $refundPercent, 100),
        ]);
    }

    // ------------------------------------------------------------------
    // Guardian decisions (called by the safety domain through the integration)
    // ------------------------------------------------------------------

    public function applyGuardianDecision(TutorSession|TutorSessionParticipant|TutorPackagePurchase $subject, bool $approved, ?User $guardian = null, ?string $reason = null): void
    {
        $status = $approved ? GuardianApprovalsPort::APPROVED : GuardianApprovalsPort::DECLINED;

        if ($subject instanceof TutorSession) {
            $this->machine->transition(
                $subject,
                $approved ? SessionStatus::Pending : SessionStatus::Cancelled,
                $guardian,
                $approved ? 'guardian_approved' : 'guardian_declined',
                $reason,
                ['guardian_status' => $status] + ($approved ? [] : ['cancelled_at' => now(), 'decision_reason' => $reason ?? 'A guardian declined this booking.']),
                after: fn (TutorSession $locked) => $approved ? null : $this->returnPackageCredit($locked),
            );

            return;
        }

        if ($subject instanceof TutorSessionParticipant) {
            DB::transaction(function () use ($subject, $approved, $guardian, $reason, $status) {
                $session = TutorSession::query()->whereKey($subject->tutor_session_id)->lockForUpdate()->firstOrFail();
                $seat = TutorSessionParticipant::query()->whereKey($subject->id)->lockForUpdate()->firstOrFail();
                if ($seat->status !== SeatStatus::AwaitingGuardian->value) {
                    throw new MarketplaceException('invalid_transition', 'This seat is not waiting for a guardian.', 409);
                }
                $to = match (true) {
                    ! $approved => SeatStatus::Cancelled,
                    $seat->price_minor > 0 => SeatStatus::AwaitingPayment,
                    default => SeatStatus::Confirmed,
                };
                $seat->update([
                    'status' => $to->value,
                    'guardian_status' => $status,
                    'hold_expires_at' => $to === SeatStatus::AwaitingPayment ? now()->addMinutes((int) config('marketplace.payment_hold_minutes')) : null,
                    'cancelled_at' => $approved ? null : now(),
                ]);
                if (! $approved) {
                    $this->releaseSeat($session);
                }
                $this->machine->record($session, $approved ? 'guardian_approved' : 'guardian_declined', $guardian, SeatStatus::AwaitingGuardian->value, $to->value, $reason, [], $seat->id);
            });

            return;
        }

        DB::transaction(function () use ($subject, $approved, $status) {
            $purchase = TutorPackagePurchase::query()->whereKey($subject->id)->lockForUpdate()->firstOrFail();
            if ($purchase->status !== TutorPackagePurchase::AWAITING_GUARDIAN) {
                throw new MarketplaceException('invalid_transition', 'This package is not waiting for a guardian.', 409);
            }
            $free = $purchase->price_minor === 0;
            $purchase->update([
                'guardian_status' => $status,
                'status' => match (true) {
                    ! $approved => TutorPackagePurchase::CANCELLED,
                    $free => TutorPackagePurchase::ACTIVE,
                    default => TutorPackagePurchase::AWAITING_PAYMENT,
                },
                'expires_at' => ($approved && $free && $purchase->offering->package_valid_days) ? now()->addDays($purchase->offering->package_valid_days) : null,
            ]);
        });
    }

    // ------------------------------------------------------------------
    // Expiry (scheduled)
    // ------------------------------------------------------------------

    /** @return array{seats: int, bookings: int, packages: int} */
    public function expireHolds(): array
    {
        $counts = ['seats' => 0, 'bookings' => 0, 'packages' => 0];

        TutorSessionParticipant::query()
            ->where('status', SeatStatus::AwaitingPayment->value)
            ->where('hold_expires_at', '<', now())
            ->pluck('tutor_session_id')->unique()
            ->each(function ($sessionId) use (&$counts) {
                DB::transaction(function () use ($sessionId, &$counts) {
                    $session = TutorSession::query()->whereKey($sessionId)->lockForUpdate()->first();
                    if ($session !== null) {
                        $counts['seats'] += $this->releaseExpiredSeatHolds($session);
                    }
                });
            });

        // Seats still waiting for a guardian when the lesson starts.
        TutorSessionParticipant::query()
            ->where('status', SeatStatus::AwaitingGuardian->value)
            ->whereHas('session', fn ($q) => $q->where('starts_at', '<', now()))
            ->get()
            ->each(function (TutorSessionParticipant $seat) use (&$counts) {
                DB::transaction(function () use ($seat, &$counts) {
                    $session = TutorSession::query()->whereKey($seat->tutor_session_id)->lockForUpdate()->firstOrFail();
                    $seat->update(['status' => SeatStatus::Cancelled->value, 'cancelled_at' => now()]);
                    $this->releaseSeat($session);
                    $this->machine->record($session, 'seat_expired', null, SeatStatus::AwaitingGuardian->value, SeatStatus::Cancelled->value, 'No guardian decision before the lesson started.', [], $seat->id);
                    $counts['seats']++;
                });
            });

        $stale = TutorSession::query()
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->where('status', SessionStatus::AwaitingPayment->value)->where('hold_expires_at', '<', now()))
                ->orWhere(fn ($w) => $w->where('status', SessionStatus::AwaitingGuardian->value)->where('starts_at', '<', now())))
            ->get();
        foreach ($stale as $session) {
            try {
                $this->machine->transition($session, SessionStatus::Cancelled, null, 'expired',
                    $session->status === SessionStatus::AwaitingPayment->value ? 'Payment was not completed in time.' : 'No guardian decision before the lesson started.',
                    ['cancelled_at' => now()],
                    after: fn (TutorSession $locked) => $this->returnPackageCredit($locked));
                $counts['bookings']++;
            } catch (MarketplaceException) {
                // Moved on since it was read (paid or approved meanwhile): leave it.
            }
        }

        $counts['packages'] = TutorPackagePurchase::query()
            ->where('status', TutorPackagePurchase::ACTIVE)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->update(['status' => TutorPackagePurchase::EXPIRED]);

        return $counts;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function lockBookableTutor(TutorOffering $offering, User $learner): TutorProfile
    {
        $profile = TutorProfile::query()->whereKey($offering->tutor_profile_id)->lockForUpdate()->firstOrFail();
        $fresh = TutorOffering::query()->whereKey($offering->id)->first();
        if (! $profile->isApproved() || $fresh === null || ! $fresh->is_active) {
            throw new MarketplaceException('not_bookable', 'This offering is not available for booking.', 404);
        }
        if ($profile->user_id === $learner->id) {
            throw new MarketplaceException('forbidden', 'You cannot book yourself.', 403);
        }

        return $profile;
    }

    private function assertSafe(User $actor, string $ability, mixed $subject): void
    {
        $decision = $this->safeguarding->allows($actor, $ability, $subject, ['domain' => 'marketplace', 'action' => $ability.'_'.class_basename($subject)]);
        if (! $decision->allowed) {
            throw new MarketplaceException('safeguarding', 'This is not allowed for your account: '.implode('; ', $decision->reasons ?: ['safeguarding rule']).'.', 403);
        }
    }

    private function subjectFor(TutorOffering $offering, int $subjectId): Subject
    {
        $subject = $offering->subjects()->where('subjects.id', $subjectId)->first();
        if (! $subject instanceof Subject) {
            throw ValidationException::withMessages(['subject_id' => 'This offering does not cover the selected subject.']);
        }
        if (! $subject->is_active) {
            throw ValidationException::withMessages(['subject_id' => 'That subject is no longer offered for new bookings.']);
        }

        return $subject;
    }

    private function deliveryFor(TutorOffering $offering, ?string $requested): string
    {
        return match ($offering->format) {
            TeachingFormat::Online->value => 'online',
            TeachingFormat::InPerson->value => 'in_person',
            default => in_array($requested, ['online', 'in_person'], true)
                ? $requested
                : throw ValidationException::withMessages(['delivery' => 'Choose online or in person.']),
        };
    }

    private function parseStart(string $startsAt, TutorProfile $profile): Carbon
    {
        $start = Carbon::parse($startsAt, $profile->scheduleTimezone())->utc()->startOfMinute();
        if ($start->lte(now())) {
            throw ValidationException::withMessages(['starts_at' => 'Pick a time in the future.']);
        }

        return $start;
    }

    private function lockUsablePackage(int $purchaseId, User $learner, TutorProfile $profile): TutorPackagePurchase
    {
        $purchase = TutorPackagePurchase::query()->whereKey($purchaseId)->lockForUpdate()->first();
        if ($purchase === null || $purchase->learner_id !== $learner->id || $purchase->tutor_profile_id !== $profile->id) {
            throw new MarketplaceException('package_not_found', 'That package was not found for this tutor.', 404);
        }
        if ($purchase->status !== TutorPackagePurchase::ACTIVE) {
            throw new MarketplaceException('package_not_active', match ($purchase->status) {
                TutorPackagePurchase::AWAITING_PAYMENT => 'This package has not been paid for yet.',
                TutorPackagePurchase::AWAITING_GUARDIAN => 'This package is waiting for a guardian\'s approval.',
                TutorPackagePurchase::EXHAUSTED => 'Every lesson in this package has been used.',
                TutorPackagePurchase::EXPIRED => 'This package has expired.',
                default => 'This package cannot be used.',
            }, 409);
        }
        if ($purchase->expires_at !== null && $purchase->expires_at->isPast()) {
            throw new MarketplaceException('package_not_active', 'This package has expired.', 409);
        }
        if ($purchase->creditsLeft() < 1) {
            throw new MarketplaceException('package_not_active', 'Every lesson in this package has been used.', 409);
        }

        return $purchase;
    }

    private function returnPackageCredit(TutorSession $session): void
    {
        if ($session->tutor_package_purchase_id === null) {
            return;
        }
        $purchase = TutorPackagePurchase::query()->whereKey($session->tutor_package_purchase_id)->lockForUpdate()->first();
        if ($purchase === null || $purchase->lessons_used < 1) {
            return;
        }
        $purchase->decrement('lessons_used');
        if ($purchase->status === TutorPackagePurchase::EXHAUSTED) {
            $purchase->update(['status' => TutorPackagePurchase::ACTIVE]);
        }
    }

    /** Race-safe: only succeeds while seats_taken < capacity in the database, whatever the caller read. */
    private function takeSeat(TutorSession $session): bool
    {
        return DB::table('tutor_sessions')
            ->where('id', $session->id)
            ->where('status', SessionStatus::Confirmed->value)
            ->whereColumn('seats_taken', '<', 'capacity')
            ->increment('seats_taken') === 1;
    }

    private function releaseSeat(TutorSession $session): void
    {
        DB::table('tutor_sessions')->where('id', $session->id)->where('seats_taken', '>', 0)->decrement('seats_taken');
    }

    /** Cancel seats on this (locked) session whose payment hold has run out. */
    private function releaseExpiredSeatHolds(TutorSession $session): int
    {
        $expired = TutorSessionParticipant::query()
            ->where('tutor_session_id', $session->id)
            ->where('status', SeatStatus::AwaitingPayment->value)
            ->where('hold_expires_at', '<', now())
            ->get();

        foreach ($expired as $seat) {
            $seat->update(['status' => SeatStatus::Cancelled->value, 'cancelled_at' => now(), 'hold_expires_at' => null]);
            $this->releaseSeat($session);
            $this->machine->record($session, 'seat_expired', null, SeatStatus::AwaitingPayment->value, SeatStatus::Cancelled->value, 'Payment was not completed in time.', [], $seat->id);
        }

        return $expired->count();
    }

    private function cancelAllSeats(TutorSession $session, User $actor, string $reason): void
    {
        foreach ($session->participants()->whereIn('status', SeatStatus::holding())->get() as $seat) {
            $from = $seat->status;
            $seat->update(['status' => SeatStatus::Cancelled->value, 'cancelled_at' => now(), 'hold_expires_at' => null]);
            $this->releaseSeat($session);
            $this->machine->record($session, 'seat_cancelled', $actor, $from, SeatStatus::Cancelled->value, $reason, [
                'by' => 'tutor',
                'refund_due_percent' => $seat->payment_status === 'paid' ? 100 : 0,
                'refund_due_minor' => $seat->payment_status === 'paid' ? (int) $seat->price_minor : 0,
            ], $seat->id);
        }
    }

    /** @return array{percent: int, minor: int, rule: string} */
    private function refundDue(TutorSession $session, bool $byTutor, ?int $paidMinor = null): array
    {
        $paid = $paidMinor ?? ($session->payment_status === 'paid' ? (int) $session->price_minor : 0);
        if ($paid === 0) {
            return ['percent' => 0, 'minor' => 0, 'rule' => 'nothing_paid'];
        }
        if ($byTutor) {
            return ['percent' => 100, 'minor' => $paid, 'rule' => 'tutor_cancelled'];
        }

        $offering = $session->offering;
        $notice = $offering?->cancellation_notice_hours ?? 24;
        $hoursBefore = (int) floor((($session->starts_at->getTimestamp() - now()->getTimestamp()) / 3600));
        if ($hoursBefore >= $notice) {
            return ['percent' => 100, 'minor' => $paid, 'rule' => 'within_free_cancellation'];
        }
        $percent = (int) ($offering?->late_cancellation_refund_percent ?? 0);

        return ['percent' => $percent, 'minor' => intdiv($paid * $percent, 100), 'rule' => 'late_cancellation'];
    }

    private function paymentDeadline(TutorSession $session): Carbon
    {
        $window = now()->addHours((int) config('marketplace.payment_window_hours'));

        return $session->starts_at->lt($window) ? $session->starts_at->copy() : $window;
    }

    private function assertTutor(TutorSession $session, User $user): void
    {
        if ($session->tutorProfile->user_id !== $user->id) {
            throw new MarketplaceException('forbidden', 'Only the tutor can do this.', 403);
        }
    }

    private function assertParty(TutorSession $session, User $user): void
    {
        $isTutor = $session->tutorProfile->user_id === $user->id;
        $isLearner = $session->student_id !== null && $session->student_id === $user->id;
        $isSeat = $session->isGroup() && $session->participants()->where('learner_id', $user->id)->whereIn('status', [...SeatStatus::holding(), SeatStatus::Attended->value])->exists();
        if (! $isTutor && ! $isLearner && ! $isSeat) {
            throw new MarketplaceException('forbidden', 'Only the people in this lesson can do this.', 403);
        }
    }
}
