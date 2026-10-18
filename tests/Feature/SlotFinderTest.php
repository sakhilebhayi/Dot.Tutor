<?php

namespace Tests\Feature;

use App\Models\TutorAvailabilityWindow;
use App\Models\TutorBlockedDate;
use App\Services\Scheduling\SlotFinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsMarketplace;
use Tests\TestCase;

/**
 * Real computation, not a stub: every case here is a wall-clock edge that
 * has bitten scheduling code before (DST transitions, tutor-local "today",
 * weekday taken in the tutor's zone rather than UTC).
 */
class SlotFinderTest extends TestCase
{
    use BuildsMarketplace;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function window($profile, int $weekday, string $start, string $end): void
    {
        TutorAvailabilityWindow::create([
            'tutor_profile_id' => $profile->id,
            'weekday' => $weekday, 'start_time' => $start, 'end_time' => $end,
        ]);
    }

    /** @return list<string> local H:i of every slot on the given local date */
    private function localTimes($days, string $date): array
    {
        foreach ($days as $day) {
            if ($day['date'] === $date) {
                return array_map(fn ($slot) => $slot->format('H:i'), $day['slots']);
            }
        }

        return [];
    }

    public function test_it_covers_the_next_fourteen_tutor_local_days_and_nothing_beyond(): void
    {
        Carbon::setTestNow('2026-10-18 06:00:00'); // Sunday
        $profile = $this->makeTutor(['timezone' => 'UTC']);
        $this->openWeek($profile, '09:00', '10:00');

        $days = app(SlotFinder::class)->forTutor($profile, 60);

        $this->assertCount(14, $days);
        $this->assertSame('2026-10-18', $days[0]['date']);
        $this->assertSame('2026-10-31', $days[13]['date']);
        $this->assertSame(['09:00'], $this->localTimes($days, '2026-10-20'));
    }

    public function test_slots_step_by_thirty_minutes_and_must_fit_the_whole_session(): void
    {
        Carbon::setTestNow('2026-10-18 06:00:00');
        $profile = $this->makeTutor(['timezone' => 'UTC']);
        $this->window($profile, 1, '09:00', '11:00'); // Monday

        $days = app(SlotFinder::class)->forTutor($profile, 60);

        $this->assertSame(['09:00', '09:30', '10:00'], $this->localTimes($days, '2026-10-19'));
        $this->assertSame(['09:00', '09:30', '10:00', '10:30'], $this->localTimes(app(SlotFinder::class)->forTutor($profile, 30), '2026-10-19'));
        $this->assertSame(['09:00'], $this->localTimes(app(SlotFinder::class)->forTutor($profile, 120), '2026-10-19'));
        $this->assertSame([], $this->localTimes(app(SlotFinder::class)->forTutor($profile, 120), '2026-10-20'));
    }

    public function test_past_slots_today_are_excluded(): void
    {
        Carbon::setTestNow('2026-10-19 09:45:00'); // Monday, UTC tutor
        $profile = $this->makeTutor(['timezone' => 'UTC']);
        $this->window($profile, 1, '09:00', '12:00');

        $days = app(SlotFinder::class)->forTutor($profile, 60);

        $this->assertSame(['10:00', '10:30', '11:00'], $this->localTimes($days, '2026-10-19'));
    }

    public function test_blocked_dates_have_no_slots(): void
    {
        Carbon::setTestNow('2026-10-18 06:00:00');
        $profile = $this->makeTutor(['timezone' => 'UTC']);
        $this->openWeek($profile, '09:00', '10:00');
        TutorBlockedDate::create(['tutor_profile_id' => $profile->id, 'date' => '2026-10-21']);

        $days = app(SlotFinder::class)->forTutor($profile, 60);

        $this->assertSame([], $this->localTimes($days, '2026-10-21'));
        $this->assertSame(['09:00'], $this->localTimes($days, '2026-10-22'));
    }

    public function test_booked_pending_and_confirmed_sessions_remove_overlapping_slots_but_cancelled_ones_do_not(): void
    {
        Carbon::setTestNow('2026-10-18 06:00:00');
        $subject = $this->makeSubject();
        $profile = $this->makeTutor(['timezone' => 'UTC'], $subject);
        $this->window($profile, 1, '09:00', '12:00');
        $student = $this->makeUser();
        $this->makeSession($profile, $student, $subject, ['status' => 'confirmed', 'starts_at' => '2026-10-19 10:00:00']);
        $this->makeSession($profile, $student, $subject, ['status' => 'cancelled', 'starts_at' => '2026-10-19 09:00:00']);

        $days = app(SlotFinder::class)->forTutor($profile, 60);

        // 10:00-11:00 is taken: 09:30 would run into it, 10:30 starts inside it. 09:00 (cancelled) and 11:00 stay.
        $this->assertSame(['09:00', '11:00'], $this->localTimes($days, '2026-10-19'));
    }

    public function test_the_tutors_timezone_decides_which_day_it_is_and_which_weekday_a_window_belongs_to(): void
    {
        // 23:30 UTC on Sunday 18 Oct is already 12:30 on Monday 19 Oct in Auckland (NZDT, +13).
        Carbon::setTestNow('2026-10-18 23:30:00');
        $profile = $this->makeTutor(['timezone' => 'Pacific/Auckland']);
        $this->window($profile, 1, '09:00', '10:00'); // Monday, Auckland time
        $this->window($profile, 2, '09:00', '10:00');

        $days = app(SlotFinder::class)->forTutor($profile, 60);

        $this->assertSame('2026-10-19', $days[0]['date']);
        // Monday's 09:00 already passed locally (now is 12:30 Monday). Tuesday's has not.
        $this->assertSame([], $this->localTimes($days, '2026-10-19'));
        $this->assertSame(['09:00'], $this->localTimes($days, '2026-10-20'));
        $instant = collect($days)->firstWhere('date', '2026-10-20')['slots'][0];
        $this->assertSame('2026-10-19 20:00:00', $instant->copy()->utc()->format('Y-m-d H:i:s'));
    }

    public function test_spring_forward_the_missing_hour_never_appears_and_the_window_is_shorter(): void
    {
        // New York, Sunday 8 March 2026: 02:00 EST jumps to 03:00 EDT.
        Carbon::setTestNow('2026-03-02 12:00:00');
        $profile = $this->makeTutor(['timezone' => 'America/New_York']);
        $this->window($profile, 7, '01:00', '04:00'); // Sunday; only 2 real hours long that day
        $this->window($profile, 7, '05:00', '07:00');

        $days = app(SlotFinder::class)->forTutor($profile, 60);

        $this->assertSame(['01:00', '01:30', '03:00', '05:00', '05:30', '06:00'], $this->localTimes($days, '2026-03-08'));
        // A normal Sunday a week later has the full three hours: 01:00..03:00 starts.
        $this->assertSame(['01:00', '01:30', '02:00', '02:30', '03:00', '05:00', '05:30', '06:00'], $this->localTimes($days, '2026-03-15'));
    }

    public function test_fall_back_the_repeated_hour_yields_distinct_real_instants(): void
    {
        // New York, Sunday 1 November 2026: 02:00 EDT falls back to 01:00 EST.
        Carbon::setTestNow('2026-10-25 12:00:00');
        $profile = $this->makeTutor(['timezone' => 'America/New_York']);
        $this->window($profile, 7, '00:00', '03:00'); // four real hours that day

        $days = app(SlotFinder::class)->forTutor($profile, 60);
        $slots = collect($days)->firstWhere('date', '2026-11-01')['slots'];

        $this->assertCount(7, $slots);
        $this->assertCount(7, collect($slots)->map(fn ($s) => $s->copy()->utc()->timestamp)->unique());
        $this->assertSame(['00:00', '00:30', '01:00', '01:30', '01:00', '01:30', '02:00'], array_map(fn ($s) => $s->format('H:i'), $slots));
        $this->assertSame(['EDT', 'EDT', 'EDT', 'EDT', 'EST', 'EST', 'EST'], array_map(fn ($s) => $s->format('T'), $slots));
    }

    public function test_a_window_starting_inside_the_missing_hour_does_not_invent_time(): void
    {
        Carbon::setTestNow('2026-03-02 12:00:00');
        $profile = $this->makeTutor(['timezone' => 'America/New_York']);
        $this->window($profile, 7, '02:30', '03:30'); // 02:30 does not exist on 8 March

        $days = app(SlotFinder::class)->forTutor($profile, 30);

        $this->assertSame([], $this->localTimes($days, '2026-03-08'));
        $this->assertSame(['02:30', '03:00'], $this->localTimes($days, '2026-03-15'));
    }

    public function test_fits_reports_why_a_requested_start_is_refused(): void
    {
        Carbon::setTestNow('2026-10-18 06:00:00');
        $profile = $this->makeTutor(['timezone' => 'UTC']);
        $this->window($profile, 1, '09:00', '11:00');
        TutorBlockedDate::create(['tutor_profile_id' => $profile->id, 'date' => '2026-10-26']);
        $finder = app(SlotFinder::class);

        $this->assertNull($finder->refusalReason($profile, Carbon::parse('2026-10-19 10:00:00 UTC'), 60));
        $this->assertNull($finder->refusalReason($profile, Carbon::parse('2026-10-19 09:10:00 UTC'), 60)); // off-grid but inside the window
        $this->assertSame('outside_hours', $finder->refusalReason($profile, Carbon::parse('2026-10-19 10:30:00 UTC'), 60));
        $this->assertSame('outside_hours', $finder->refusalReason($profile, Carbon::parse('2026-10-20 10:00:00 UTC'), 60));
        $this->assertSame('blocked', $finder->refusalReason($profile, Carbon::parse('2026-10-26 10:00:00 UTC'), 60));
    }
}
