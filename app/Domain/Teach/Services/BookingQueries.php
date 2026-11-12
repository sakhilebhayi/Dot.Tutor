<?php

namespace App\Domain\Teach\Services;

use App\Domain\Marketplace\Models\TutorBookingSeries;
use App\Enums\SessionStatus;
use App\Models\TutorProfile;
use App\Models\TutorSession;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The tutor's own bookings, always scoped by tutor_profile_id. Shared by the home, bookings, calendar and students
 * screens so they count the same things the same way.
 */
class BookingQueries
{
    /** States a lesson in the calendar can be in (booked or being decided). */
    public const ON_CALENDAR = ['pending', 'awaiting_payment', 'confirmed', 'in_progress', 'completed', 'no_show', 'disputed'];

    /** @return Builder<TutorSession> */
    public function base(TutorProfile $profile): Builder
    {
        return TutorSession::query()->where('tutor_profile_id', $profile->id)
            ->with(['student:id,name', 'subject:id,name', 'offering:id,title,type,capacity']);
    }

    /** @return Collection<int, TutorSession> */
    public function requests(TutorProfile $profile): Collection
    {
        return $this->base($profile)->where('status', SessionStatus::Pending->value)->orderBy('starts_at')->get();
    }

    /** @return Collection<int, TutorBookingSeries> */
    public function pendingSeries(TutorProfile $profile): Collection
    {
        return TutorBookingSeries::query()->where('tutor_profile_id', $profile->id)
            ->whereHas('sessions', fn ($q) => $q->where('status', SessionStatus::Pending->value))
            ->with(['sessions' => fn ($q) => $q->orderBy('starts_at')])->orderBy('id')->get();
    }

    /**
     * How many requests from under-18s wait for a guardian. Only the number: the tutor learns who and when once the
     * guardian approves (comms D-1017, tutor UI D-1208), so a refused request never reaches them.
     */
    public function guardianHoldCount(TutorProfile $profile): int
    {
        return TutorSession::query()->where('tutor_profile_id', $profile->id)->where('status', SessionStatus::AwaitingGuardian->value)->count();
    }

    /** @return Collection<int, TutorSession> */
    public function between(TutorProfile $profile, CarbonImmutable $from, CarbonImmutable $to, array $states = ['confirmed', 'in_progress', 'awaiting_payment']): Collection
    {
        return $this->base($profile)->whereIn('status', $states)
            ->where('starts_at', '>=', $from->utc())->where('starts_at', '<', $to->utc())
            ->orderBy('starts_at')->get();
    }

    /**
     * Learners and lessons that need the tutor's attention, each with the reason in words.
     *
     * @return list<array{session: TutorSession, reason: string}>
     */
    public function needingAttention(TutorProfile $profile): array
    {
        $now = CarbonImmutable::now();
        $items = [];

        foreach ($this->base($profile)->where('status', SessionStatus::Disputed->value)->orderBy('starts_at')->limit(10)->get() as $s) {
            $items[] = ['session' => $s, 'reason' => 'Disputed. An operator is reviewing it; add your side in messages if asked.'];
        }
        foreach ($this->base($profile)->where('status', SessionStatus::InProgress->value)->where('starts_at', '<', $now->subHours(4))->limit(10)->get() as $s) {
            $items[] = ['session' => $s, 'reason' => 'Started but never completed. Mark it complete so the earning can be released.'];
        }
        foreach ($this->base($profile)->where('status', SessionStatus::Confirmed->value)->where('starts_at', '<', $now->subHours(2))->limit(10)->get() as $s) {
            $items[] = ['session' => $s, 'reason' => 'Its time has passed. Complete it or record a no-show.'];
        }
        foreach ($this->base($profile)->where('status', SessionStatus::AwaitingPayment->value)->whereNotNull('hold_expires_at')
            ->where('hold_expires_at', '<', $now->addDay())->limit(10)->get() as $s) {
            $items[] = ['session' => $s, 'reason' => 'Accepted but not paid; the hold ends soon and the time is released.'];
        }
        foreach ($this->base($profile)->where('status', SessionStatus::NoShow->value)->where('starts_at', '>', $now->subDays(14))->limit(10)->get() as $s) {
            $items[] = ['session' => $s, 'reason' => 'Recorded as a no-show in the last two weeks. Finance reviews any payment.'];
        }

        return $items;
    }
}
