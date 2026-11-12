<?php

namespace App\Services\Scheduling;

use App\Enums\SessionStatus;
use App\Models\TutorProfile;
use App\Models\TutorSession;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Turns a tutor's weekly hours, blocked dates and existing bookings into the
 * start times a student can actually pick.
 *
 * All wall-clock maths happens in the tutor's own timezone, but every
 * comparison and every step is done on absolute timestamps. That is what
 * keeps DST honest: on the spring-forward day a 01:00-04:00 window is two
 * real hours (so it offers 01:00, 01:30, 03:00 and never a 02:xx that does
 * not exist), and on the fall-back day a 00:00-03:00 window is four real
 * hours, with the repeated 01:xx hour offered once per occurrence.
 */
class SlotFinder
{
    /**
     * $sessions, when given, are the tutor's sessions already fetched by the caller (matching fetches every
     * candidate's in one query, R-47 D-1602); they must cover the range and are filtered here exactly as the query
     * below would. Weekly hours and blocked dates come from loaded relations when the caller eager-loaded them.
     *
     * @param  Collection<int, TutorSession>|null  $sessions
     * @return list<array{date: string, slots: list<CarbonImmutable>}> one entry per
     *                                                                 tutor-local day, today first, including days with no slots
     */
    public function forTutor(TutorProfile $profile, int $durationMinutes, ?CarbonInterface $now = null, ?int $days = null, ?Collection $sessions = null): array
    {
        $tz = $profile->scheduleTimezone();

        return array_map(fn (array $day) => [
            'date' => $day['date'],
            'slots' => array_map(fn (int $t) => CarbonImmutable::createFromTimestamp($t, $tz), $day['starts']),
        ], $this->startTimestamps($profile, $durationMinutes, $now, $days, $sessions));
    }

    /**
     * forTutor() as plain Unix timestamps: the same slots, without a Carbon object per slot. Matching scores
     * hundreds of tutors with thousands of open slots per search and only counts them (R-47 D-1602).
     *
     * @param  Collection<int, TutorSession>|null  $sessions
     * @return list<array{date: string, starts: list<int>}>
     */
    public function startTimestamps(TutorProfile $profile, int $durationMinutes, ?CarbonInterface $now = null, ?int $days = null, ?Collection $sessions = null): array
    {
        $tz = $profile->scheduleTimezone();
        $nowTs = ($now ?? Carbon::now())->getTimestamp();
        $days ??= (int) config('tutor.booking_horizon_days');
        $stepSeconds = max(1, (int) config('tutor.slot_step_minutes')) * 60;
        $durationSeconds = $durationMinutes * 60;

        $windows = ($profile->relationLoaded('availabilityWindows') ? $profile->availabilityWindows : $profile->availabilityWindows()->get())->groupBy('weekday');
        $blocked = $this->blockedDates($profile);

        // Date arithmetic on plain 'Y-m-d' strings in UTC: no DST can touch it.
        $firstDate = CarbonImmutable::createFromTimestamp($nowTs, $tz)->toDateString();

        $result = [];
        $dayRows = [];
        for ($i = 0; $i < $days; $i++) {
            $date = CarbonImmutable::parse($firstDate, 'UTC')->addDays($i);
            $dayRows[] = [$date->toDateString(), $date->isoWeekday()];
        }

        $busy = $this->busyIntervals(
            $profile,
            $this->localInstant($dayRows[0][0], '00:00', $tz)->getTimestamp(),
            $this->localInstant(CarbonImmutable::parse($dayRows[$days - 1][0], 'UTC')->addDay()->toDateString(), '00:00', $tz)->getTimestamp(),
            $sessions,
        )->all();
        $zone = new \DateTimeZone($tz);

        foreach ($dayRows as [$date, $weekday]) {
            $starts = [];

            if (! isset($blocked[$date])) {
                foreach ($windows->get($weekday, []) as $window) {
                    $from = $this->localTimestamp($date, $window->start_time, $zone);
                    $to = $this->localTimestamp($date, $window->end_time, $zone);

                    for ($t = $from; $t + $durationSeconds <= $to; $t += $stepSeconds) {
                        if ($t <= $nowTs || $this->collides($busy, $t, $t + $durationSeconds)) {
                            continue;
                        }
                        $starts[] = $t;
                    }
                }
            }

            sort($starts);
            $result[] = ['date' => $date, 'starts' => $starts];
        }

        return $result;
    }

    /**
     * Why a requested start cannot be booked on hours/blocked-date grounds, or
     * null when it falls inside an open window. Deliberately does not look at
     * other bookings: the booking service checks those under a lock.
     *
     * @return 'blocked'|'outside_hours'|null
     */
    public function refusalReason(TutorProfile $profile, CarbonInterface $start, int $durationMinutes): ?string
    {
        $tz = $profile->scheduleTimezone();
        $local = CarbonImmutable::createFromTimestamp($start->getTimestamp(), $tz);
        $date = $local->toDateString();

        if (isset($this->blockedDates($profile)[$date])) {
            return 'blocked';
        }

        $startTs = $start->getTimestamp();
        $endTs = $startTs + $durationMinutes * 60;

        $windows = $profile->availabilityWindows()->where('weekday', $local->isoWeekday())->get();
        foreach ($windows as $window) {
            $from = $this->localInstant($date, $window->start_time, $tz)->getTimestamp();
            $to = $this->localInstant($date, $window->end_time, $tz)->getTimestamp();

            if ($startTs >= $from && $endTs <= $to) {
                return null;
            }
        }

        return 'outside_hours';
    }

    /** localInstant() as a timestamp without a Carbon object (same parsing: native DateTime under Carbon). */
    private function localTimestamp(string $date, string $time, \DateTimeZone $zone): int
    {
        return \DateTimeImmutable::createFromFormat('!Y-m-d H:i', "{$date} {$time}", $zone)->getTimestamp();
    }

    /** A local wall-clock moment as an absolute instant; a time skipped by DST resolves forward. */
    private function localInstant(string $date, string $time, string $tz): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d H:i', "{$date} {$time}", $tz);
    }

    /** @return array<string, bool> */
    private function blockedDates(TutorProfile $profile): array
    {
        return ($profile->relationLoaded('blockedDates') ? $profile->blockedDates->pluck('date') : $profile->blockedDates()->pluck('date'))
            ->mapWithKeys(fn ($d) => [substr((string) $d, 0, 10) => true])
            ->all();
    }

    /**
     * @param  Collection<int, TutorSession>|null  $sessions  pre-fetched sessions (see forTutor)
     * @return Collection<int, array{0: int, 1: int}> [startTs, endTs] of the tutor's pending/confirmed sessions in range
     */
    private function busyIntervals(TutorProfile $profile, int $fromTs, int $toTs, ?Collection $sessions = null): Collection
    {
        $longest = TutorSession::longestDurationMinutes();

        if ($sessions !== null) {
            $holding = SessionStatus::holdingTime();

            return $sessions
                ->filter(fn (TutorSession $s) => (int) $s->tutor_profile_id === (int) $profile->id
                    && in_array($s->status, $holding, true)
                    && $s->starts_at->getTimestamp() < $toTs
                    && $s->starts_at->getTimestamp() > $fromTs - $longest * 60)
                ->map(fn (TutorSession $s) => [$s->starts_at->getTimestamp(), $s->endsAt()->getTimestamp()])
                ->values();
        }

        return TutorSession::query()
            ->where('tutor_profile_id', $profile->id)
            ->whereIn('status', SessionStatus::holdingTime())
            ->where('starts_at', '<', Carbon::createFromTimestampUTC($toTs))
            ->where('starts_at', '>', Carbon::createFromTimestampUTC($fromTs - $longest * 60))
            ->get()
            ->map(fn (TutorSession $s) => [$s->starts_at->getTimestamp(), $s->endsAt()->getTimestamp()]);
    }

    /** @param  list<array{0: int, 1: int}>  $busy */
    private function collides(array $busy, int $startTs, int $endTs): bool
    {
        foreach ($busy as [$from, $to]) {
            if ($from < $endTs && $to > $startTs) {
                return true;
            }
        }

        return false;
    }
}
