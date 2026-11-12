<?php

namespace Tests\Feature\Student;

use App\Domain\Comms\Messenger;
use App\Domain\Comms\Models\UserCommsSettings;
use App\Domain\Money\Models\OrderLine;
use App\Domain\Student\Support\Times;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsMoney;
use Tests\TestCase;

/**
 * R-47 polish (D-2001): the same lesson read 08:00 on the order and receipt but 10:00 SAST in the notification. The
 * order line title was frozen from the UTC wall clock. Every learner-facing time is now in the reader's zone, and
 * a time that travels alone names its zone.
 */
class LearnerTimesTest extends TestCase
{
    use BuildsMoney;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPaystack();
    }

    public function test_the_order_line_and_the_notification_name_the_same_local_time(): void
    {
        [$session, $learner] = $this->lesson();
        $startsUtc = CarbonImmutable::now('UTC')->addDays(3)->setTime(8, 0);
        $session->forceFill(['starts_at' => $startsUtc])->save();
        $day = $startsUtc->format('j M Y');

        $uuid = $this->placeOrder($learner, [$this->bookingItem($session->fresh())])->assertCreated()->json('order.id');
        $title = (string) OrderLine::query()->value('title');
        $this->assertStringContainsString($day.' 10:00 SAST', $title);
        $this->assertStringNotContainsString($day.' 08:00', $title);

        $this->actingAs($learner)->get("/money/checkout/{$uuid}")->assertOk()
            ->assertSee($day.' 10:00 SAST')->assertDontSee($day.' 08:00');

        // The notification text for the same lesson agrees.
        $this->assertStringContainsString('10:00 SAST', app(Messenger::class)->when($startsUtc, $learner));
    }

    public function test_a_reader_with_their_own_zone_reads_times_in_it_and_the_zone_is_named(): void
    {
        [, $learner] = $this->lesson();
        $at = CarbonImmutable::parse('2026-11-15 08:00:00', 'UTC');

        $this->actingAs($learner);
        $this->assertSame('Sun 15 Nov, 10:00 SAST', Times::stamp($at));
        $this->assertSame('Times are South African time (SAST).', Times::zoneNote());

        UserCommsSettings::query()->create(['user_id' => $learner->id, 'timezone' => 'Europe/London']);
        $this->app->forgetScopedInstances();
        $this->assertSame('Sun 15 Nov, 08:00 GMT', Times::stamp($at));
        $this->assertSame('Sun 15 Nov, 08:00', Times::when($at));
        $this->assertStringContainsString('Europe/London', Times::zoneNote());
        $this->assertStringContainsString('08:00 GMT', app(Messenger::class)->when($at, $learner));

        // A guest reads the platform zone.
        auth()->logout();
        $this->assertSame('Sun 15 Nov, 10:00 SAST', Times::stamp($at));
    }
}
