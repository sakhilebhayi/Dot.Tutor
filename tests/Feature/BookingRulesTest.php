<?php

namespace Tests\Feature;

use App\Models\TutorBlockedDate;
use App\Models\TutorSession;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\BuildsMarketplace;
use Tests\TestCase;

class BookingRulesTest extends TestCase
{
    use BuildsMarketplace;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function book($student, $profile, $subject, Carbon $at, array $overrides = [])
    {
        return $this->actingAs($student)->post("/tutors/{$profile->id}/sessions", $this->bookingPayload($subject, $at, $overrides));
    }

    public function test_a_slot_inside_availability_can_be_booked(): void
    {
        $subject = $this->makeSubject();
        $profile = $this->makeTutor([], $subject);
        $this->openWeek($profile);
        $student = $this->makeUser();

        $this->book($student, $profile, $subject, $this->slotAt($profile, 2, '10:00'))->assertSessionHasNoErrors();

        $this->assertSame(1, TutorSession::count());
    }

    public function test_a_tutor_with_no_availability_cannot_be_booked(): void
    {
        $subject = $this->makeSubject();
        $profile = $this->makeTutor([], $subject);

        $this->book($this->makeUser(), $profile, $subject, $this->slotAt($profile, 2))->assertSessionHasErrors('starts_at');
        $this->assertSame(0, TutorSession::count());
    }

    public function test_a_slot_outside_availability_is_refused(): void
    {
        $subject = $this->makeSubject();
        $profile = $this->makeTutor([], $subject);
        $this->openWeek($profile, '09:00', '12:00');
        $student = $this->makeUser();

        $this->book($student, $profile, $subject, $this->slotAt($profile, 2, '07:00'))->assertSessionHasErrors('starts_at');
        // Starts inside the window but runs past its end.
        $this->book($student, $profile, $subject, $this->slotAt($profile, 2, '11:30'), ['duration_minutes' => 60])->assertSessionHasErrors('starts_at');
        $this->assertSame(0, TutorSession::count());
    }

    public function test_availability_is_read_in_the_tutors_timezone(): void
    {
        $subject = $this->makeSubject();
        $profile = $this->makeTutor(['timezone' => 'Africa/Johannesburg'], $subject); // UTC+2
        $this->openWeek($profile, '09:00', '12:00');
        $student = $this->makeUser();

        // 08:00 UTC is 10:00 in Johannesburg: inside. 10:00 UTC is 12:00 there: outside.
        $inside = Carbon::now('UTC')->addDays(2)->setTime(8, 0);
        $outside = Carbon::now('UTC')->addDays(2)->setTime(10, 0);

        $this->book($student, $profile, $subject, $outside)->assertSessionHasErrors('starts_at');
        $this->book($student, $profile, $subject, $inside)->assertSessionHasNoErrors();
        $this->assertSame(1, TutorSession::count());
    }

    public function test_a_zone_less_start_is_read_in_the_tutors_timezone(): void
    {
        $subject = $this->makeSubject();
        $profile = $this->makeTutor(['timezone' => 'Africa/Johannesburg'], $subject);
        $this->openWeek($profile, '09:00', '12:00');
        $local = Carbon::now('Africa/Johannesburg')->addDays(2)->setTime(10, 0);

        $this->actingAs($this->makeUser())->post("/tutors/{$profile->id}/sessions", $this->bookingPayload($subject, $local, [
            'starts_at' => $local->format('Y-m-d\TH:i'),
        ]))->assertSessionHasNoErrors();

        $this->assertSame($local->copy()->utc()->format('Y-m-d H:i'), TutorSession::firstOrFail()->starts_at->format('Y-m-d H:i'));
    }

    public function test_a_blocked_date_cannot_be_booked(): void
    {
        $subject = $this->makeSubject();
        $profile = $this->makeTutor([], $subject);
        $this->openWeek($profile);
        $slot = $this->slotAt($profile, 2, '10:00');
        TutorBlockedDate::create(['tutor_profile_id' => $profile->id, 'date' => $slot->toDateString()]);

        $this->book($this->makeUser(), $profile, $subject, $slot)->assertSessionHasErrors('starts_at');
        $this->assertSame(0, TutorSession::count());
    }

    public function test_overlapping_the_tutors_pending_or_confirmed_session_is_refused(): void
    {
        $subject = $this->makeSubject();
        $profile = $this->makeTutor([], $subject);
        $this->openWeek($profile);
        $first = $this->makeUser('First');
        $second = $this->makeUser('Second');
        $slot = $this->slotAt($profile, 2, '10:00');

        $this->book($first, $profile, $subject, $slot)->assertSessionHasNoErrors();
        // 10:30 runs into the 10:00-11:00 booking.
        $this->book($second, $profile, $subject, $slot->copy()->addMinutes(30))->assertSessionHasErrors('starts_at');
        // Exact same slot too.
        $this->book($second, $profile, $subject, $slot)->assertSessionHasErrors('starts_at');
        // Back-to-back is fine.
        $this->book($second, $profile, $subject, $slot->copy()->addHour())->assertSessionHasNoErrors();

        $this->assertSame(2, TutorSession::count());
    }

    public function test_a_cancelled_session_frees_the_slot(): void
    {
        $subject = $this->makeSubject();
        $profile = $this->makeTutor([], $subject);
        $this->openWeek($profile);
        $slot = $this->slotAt($profile, 2, '10:00');
        $this->makeSession($profile, $this->makeUser(), $subject, ['status' => 'cancelled', 'starts_at' => $slot]);

        $this->book($this->makeUser(), $profile, $subject, $slot)->assertSessionHasNoErrors();
        $this->assertSame(2, TutorSession::count());
    }

    public function test_a_student_cannot_be_double_booked_with_two_tutors(): void
    {
        $subject = $this->makeSubject();
        $one = $this->makeTutor([], $subject);
        $two = $this->makeTutor([], $subject, $this->makeUser('Second Tutor'));
        $this->openWeek($one);
        $this->openWeek($two);
        $student = $this->makeUser();
        $slot = $this->slotAt($one, 2, '10:00');

        $this->book($student, $one, $subject, $slot)->assertSessionHasNoErrors();
        $this->book($student, $two, $subject, $slot->copy()->addMinutes(30))->assertSessionHasErrors('starts_at');

        $this->assertSame(1, TutorSession::count());
    }

    public function test_booking_runs_in_a_transaction_that_locks_the_tutor_profile_row(): void
    {
        $subject = $this->makeSubject();
        $profile = $this->makeTutor([], $subject);
        $this->openWeek($profile);

        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });
        $began = 0;
        Event::listen(TransactionBeginning::class, function () use (&$began) {
            $began++;
        });

        $this->book($this->makeUser(), $profile, $subject, $this->slotAt($profile, 2))->assertSessionHasNoErrors();

        $this->assertGreaterThanOrEqual(1, $began);
        // SQLite has no row locks and silently drops FOR UPDATE, so here we can only prove the row is
        // re-read by key (route binding is read one; the locked re-read is the second).
        $profileReads = collect($queries)->filter(fn ($sql) => str_contains($sql, 'from "tutor_profiles"') && str_contains($sql, 'limit 1'));
        $this->assertGreaterThanOrEqual(2, $profileReads->count());
    }

    public function test_the_booking_form_is_a_list_of_real_slots_for_the_next_fourteen_days(): void
    {
        Carbon::setTestNow('2026-10-18 06:00:00');
        $subject = $this->makeSubject();
        $profile = $this->makeTutor(['timezone' => 'UTC'], $subject);
        $this->openWeek($profile, '09:00', '10:00');

        $response = $this->actingAs($this->makeUser())->get("/tutors/{$profile->id}");

        $response->assertOk()
            ->assertSee('value="2026-10-19T09:00:00Z"', false)
            ->assertSee('value="2026-10-31T09:00:00Z"', false)
            ->assertDontSee('value="2026-11-01T09:00:00Z"', false)
            ->assertSee('Payment is arranged outside the platform for now');
    }

    public function test_the_tutor_page_says_so_when_there_are_no_bookable_times(): void
    {
        $subject = $this->makeSubject();
        $profile = $this->makeTutor([], $subject);

        $this->actingAs($this->makeUser())->get("/tutors/{$profile->id}")
            ->assertOk()
            ->assertSee('has no open times in the next 14 days')
            ->assertDontSee('name="starts_at"', false);
    }

    public function test_the_tutor_page_lists_only_slots_that_fit_the_chosen_duration(): void
    {
        Carbon::setTestNow('2026-10-18 06:00:00');
        $subject = $this->makeSubject();
        $profile = $this->makeTutor(['timezone' => 'UTC'], $subject);
        $this->openWeek($profile, '09:00', '10:00');

        $this->actingAs($this->makeUser())->get("/tutors/{$profile->id}?duration=120")
            ->assertOk()
            ->assertDontSee('value="2026-10-19T09:00:00Z"', false);
    }

    public function test_booking_is_throttled_per_user(): void
    {
        $subject = $this->makeSubject();
        $profile = $this->makeTutor([], $subject);
        $student = $this->makeUser();

        for ($i = 0; $i < 10; $i++) {
            $this->book($student, $profile, $subject, $this->slotAt($profile, 2))->assertStatus(302);
        }

        $this->book($student, $profile, $subject, $this->slotAt($profile, 2))->assertStatus(429);
        // Another user is unaffected.
        $this->book($this->makeUser(), $profile, $subject, $this->slotAt($profile, 2))->assertStatus(302);
    }
}
