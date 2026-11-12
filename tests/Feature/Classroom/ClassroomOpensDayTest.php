<?php

namespace Tests\Feature\Classroom;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** R-47 fix D-2406: the pre-join page says on which day the room opens when it is not today. */
class ClassroomOpensDayTest extends TestCase
{
    use BuildsClassroom;
    use RefreshDatabase;

    public function test_the_opening_time_names_the_day_when_it_is_not_today(): void
    {
        // 06:00 UTC is 08:00 in South Africa: no midnight edge in either zone.
        $this->travelTo(CarbonImmutable::parse('2026-11-13 06:00:00', 'UTC'));
        [, $profile] = $this->tutor();
        $profile->forceFill(['timezone' => 'Africa/Johannesburg'])->save();
        $learner = $this->adult('Learner');

        $later = $this->lesson($profile, $learner, minutes: 3 * 24 * 60); // Mon 16 Nov 08:00 SAST, opens 07:50
        $this->actingAs($learner)->get(route('classroom.show', $later))->assertOk()
            ->assertSee('The classroom opens <time', false)
            ->assertSee('Mon 16 Nov at 07:50 (in 3 days)')
            ->assertSee('Join opens Mon 16 Nov at 07:50 (in 3 days).');

        $soon = $this->lesson($profile, $this->adult('Other'), minutes: 60); // 09:00 SAST today, opens 08:50
        $this->actingAs($soon->student)->get(route('classroom.show', $soon))->assertOk()->assertSee('today at 08:50');
    }
}
