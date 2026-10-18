<?php

namespace Tests\Feature;

use App\Models\TutorAvailabilityWindow;
use App\Models\TutorBlockedDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsMarketplace;
use Tests\TestCase;

class TutorAvailabilityTest extends TestCase
{
    use BuildsMarketplace;
    use RefreshDatabase;

    public function test_a_user_without_a_tutor_profile_is_sent_to_apply(): void
    {
        $this->actingAs($this->makeUser())->get('/tutor/availability')->assertRedirect(route('tutor.profile.edit'));
    }

    public function test_a_tutor_can_add_a_weekly_window(): void
    {
        $profile = $this->makeTutor(['timezone' => 'Africa/Johannesburg']);

        $this->actingAs($profile->user)
            ->post('/tutor/availability/windows', ['weekday' => 2, 'start_time' => '09:00', 'end_time' => '13:00'])
            ->assertRedirect(route('tutor.availability.index'));

        $window = TutorAvailabilityWindow::firstOrFail();
        $this->assertSame($profile->id, $window->tutor_profile_id);
        $this->assertSame(2, $window->weekday);
        $this->assertSame('09:00', $window->start_time);
        $this->assertSame('13:00', $window->end_time);

        $this->actingAs($profile->user)->get('/tutor/availability')
            ->assertOk()
            ->assertSee('Africa/Johannesburg')
            ->assertSee('09:00');
    }

    public function test_window_input_is_validated(): void
    {
        $profile = $this->makeTutor();
        $tutor = $this->actingAs($profile->user);

        $tutor->post('/tutor/availability/windows', ['weekday' => 9, 'start_time' => '09:00', 'end_time' => '10:00'])->assertSessionHasErrors('weekday');
        $tutor->post('/tutor/availability/windows', ['weekday' => 1, 'start_time' => '25:00', 'end_time' => '10:00'])->assertSessionHasErrors('start_time');
        $tutor->post('/tutor/availability/windows', ['weekday' => 1, 'start_time' => '10:00', 'end_time' => '10:00'])->assertSessionHasErrors('end_time');
        $tutor->post('/tutor/availability/windows', ['weekday' => 1, 'start_time' => '11:00', 'end_time' => '10:00'])->assertSessionHasErrors('end_time');

        $this->assertSame(0, TutorAvailabilityWindow::count());
    }

    public function test_overlapping_windows_on_the_same_weekday_are_refused_but_adjacent_ones_are_fine(): void
    {
        $profile = $this->makeTutor();
        $tutor = $this->actingAs($profile->user);
        $tutor->post('/tutor/availability/windows', ['weekday' => 3, 'start_time' => '09:00', 'end_time' => '12:00'])->assertSessionHasNoErrors();

        $tutor->post('/tutor/availability/windows', ['weekday' => 3, 'start_time' => '11:00', 'end_time' => '14:00'])->assertSessionHasErrors('start_time');
        $tutor->post('/tutor/availability/windows', ['weekday' => 3, 'start_time' => '12:00', 'end_time' => '14:00'])->assertSessionHasNoErrors();
        $tutor->post('/tutor/availability/windows', ['weekday' => 4, 'start_time' => '11:00', 'end_time' => '14:00'])->assertSessionHasNoErrors();

        $this->assertSame(3, TutorAvailabilityWindow::count());
    }

    public function test_a_tutor_can_remove_only_their_own_window(): void
    {
        $mine = $this->makeTutor();
        $theirs = $this->makeTutor([], null, $this->makeUser('Other Tutor'));
        $window = TutorAvailabilityWindow::create(['tutor_profile_id' => $mine->id, 'weekday' => 1, 'start_time' => '09:00', 'end_time' => '10:00']);

        $this->actingAs($theirs->user)->delete("/tutor/availability/windows/{$window->id}")->assertNotFound();
        $this->assertSame(1, TutorAvailabilityWindow::count());

        $this->actingAs($mine->user)->delete("/tutor/availability/windows/{$window->id}")->assertRedirect(route('tutor.availability.index'));
        $this->assertSame(0, TutorAvailabilityWindow::count());
    }

    public function test_a_tutor_can_block_and_unblock_a_date(): void
    {
        $profile = $this->makeTutor();
        $date = now()->addDays(5)->toDateString();

        $this->actingAs($profile->user)
            ->post('/tutor/availability/blocked-dates', ['date' => $date, 'reason' => 'Exam marking'])
            ->assertRedirect(route('tutor.availability.index'));

        $blocked = TutorBlockedDate::firstOrFail();
        $this->assertSame($date, $blocked->date);
        $this->assertSame('Exam marking', $blocked->reason);

        // Blocking the same date twice is refused.
        $this->actingAs($profile->user)->post('/tutor/availability/blocked-dates', ['date' => $date])->assertSessionHasErrors('date');

        $this->actingAs($profile->user)->delete("/tutor/availability/blocked-dates/{$blocked->id}")->assertRedirect();
        $this->assertSame(0, TutorBlockedDate::count());
    }

    public function test_blocking_a_date_in_the_past_is_refused(): void
    {
        $profile = $this->makeTutor();

        $this->actingAs($profile->user)
            ->post('/tutor/availability/blocked-dates', ['date' => now()->subDays(2)->toDateString()])
            ->assertSessionHasErrors('date');
    }
}
