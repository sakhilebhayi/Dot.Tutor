<?php

namespace Tests\Feature\Comms;

use App\Domain\Comms\Calendar\LessonCalendar;
use App\Domain\Comms\Models\NotificationDelivery;
use App\Domain\Comms\QuietHours;
use App\Domain\Learning\Models\Assessment;
use App\Domain\Learning\Models\Assignment;
use App\Domain\Learning\Models\Attempt;
use App\Domain\Marketplace\Models\TutorSessionEvent;
use App\Domain\Marketplace\Models\TutorSessionParticipant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Lesson reminders (24 h and 1 h, cron, idempotent), assignment due reminders, and calendar files. */
class RemindersAndCalendarTest extends TestCase
{
    use BuildsComms;
    use RefreshDatabase;

    private function confirmedLesson(CarbonImmutable $startsAt, array $tutorProfile = [], ?User $learner = null): array
    {
        [$tutor, $subject] = $this->bookableTutor([], $tutorProfile);
        $learner ??= $this->adult('Learner');
        $this->screen($tutor->user);
        $session = $this->makeSession($tutor, $learner, $subject, ['status' => 'confirmed', 'starts_at' => $startsAt]);
        TutorSessionEvent::create(['tutor_session_id' => $session->id, 'event' => 'accepted', 'from_state' => 'pending', 'to_state' => 'confirmed', 'actor_id' => $tutor->user_id]);
        TutorSessionEvent::query()->where('tutor_session_id', $session->id)->toBase()->update(['created_at' => now()->subDays(2)]);

        return [$tutor, $learner, $session];
    }

    public function test_the_day_before_reminder_goes_out_once_however_often_cron_runs(): void
    {
        [$tutor, $learner, $session] = $this->confirmedLesson(CarbonImmutable::now()->addHours(23)->addMinutes(30));

        $this->artisan('comms:reminders')->assertSuccessful();
        $this->artisan('comms:reminders')->assertSuccessful();
        $this->travel(5)->minutes();
        $this->artisan('comms:reminders')->assertSuccessful();

        $this->assertCount(1, $this->inbox($learner, 'lesson_reminder'));
        $this->assertCount(1, $this->inbox($tutor->user, 'lesson_reminder'));
        $notice = $this->inbox($learner, 'lesson_reminder')->sole();
        $this->assertStringContainsString('tomorrow', $notice->data['title']);
        $this->assertSame($session->id, $notice->data['ics_session_id']);
        $this->assertSame($session->starts_at->toIso8601String(), $notice->data['expires_at']);
    }

    public function test_the_hour_before_reminder_is_separate_and_also_once(): void
    {
        [, $learner] = $this->confirmedLesson(CarbonImmutable::now()->addMinutes(45));

        $this->artisan('comms:reminders');
        $this->artisan('comms:reminders');

        $this->assertCount(1, $this->inbox($learner, 'lesson_reminder'));
        $this->assertStringContainsString('in an hour', $this->inbox($learner, 'lesson_reminder')->sole()->data['title']);
    }

    public function test_a_lesson_moved_to_a_new_time_gets_fresh_reminders(): void
    {
        [, $learner, $session] = $this->confirmedLesson(CarbonImmutable::now()->addHours(23)->addMinutes(30));
        $this->artisan('comms:reminders');

        $session->forceFill(['starts_at' => now()->addHours(23)->addMinutes(50)])->save();
        $this->artisan('comms:reminders');

        $this->assertCount(2, $this->inbox($learner, 'lesson_reminder'));
    }

    public function test_no_reminder_for_a_lesson_just_confirmed_or_not_confirmed(): void
    {
        [$tutor, $subject] = $this->bookableTutor();
        $learner = $this->adult('Learner');
        $fresh = $this->makeSession($tutor, $learner, $subject, ['status' => 'confirmed', 'starts_at' => now()->addMinutes(40)]);
        TutorSessionEvent::create(['tutor_session_id' => $fresh->id, 'event' => 'accepted', 'from_state' => 'pending', 'to_state' => 'confirmed']);
        $this->makeSession($tutor, $learner, $subject, ['status' => 'pending', 'starts_at' => now()->addHours(23)->addMinutes(30)]);

        $this->artisan('comms:reminders');

        $this->assertCount(0, $this->inbox($learner, 'lesson_reminder'));
    }

    public function test_a_minors_guardian_gets_the_day_before_reminder_only(): void
    {
        $learner = $this->teen('Sipho Dlamini');
        $guardian = $this->guardianFor($learner);
        [$tutor, , $session] = $this->confirmedLesson(CarbonImmutable::now()->addHours(23)->addMinutes(30), [], $learner);

        $this->artisan('comms:reminders');
        $this->assertCount(1, $this->inbox($guardian, 'lesson_reminder'));
        $this->assertStringContainsString('with Sipho starts', $this->inbox($tutor->user, 'lesson_reminder')->sole()->data['message']);
        $this->assertStringNotContainsString('Dlamini', $this->inbox($tutor->user, 'lesson_reminder')->sole()->data['message']);

        $this->travelTo($session->starts_at->copy()->subMinutes(30));
        $this->artisan('comms:reminders');
        $this->assertCount(2, $this->inbox($learner, 'lesson_reminder'));
        $this->assertCount(1, $this->inbox($guardian, 'lesson_reminder'));
    }

    public function test_group_lesson_reminders_reach_each_confirmed_seat(): void
    {
        [$tutor, $subject] = $this->bookableTutor();
        $session = $this->makeSession($tutor, $this->adult('Host'), $subject, ['status' => 'confirmed', 'kind' => 'group', 'capacity' => 5, 'starts_at' => now()->addHours(23)->addMinutes(20)]);
        $a = $this->adult('Seat A');
        $b = $this->adult('Seat B');
        $c = $this->adult('Cancelled C');
        foreach ([[$a, 'confirmed'], [$b, 'confirmed'], [$c, 'cancelled']] as [$who, $status]) {
            TutorSessionParticipant::create(['tutor_session_id' => $session->id, 'learner_id' => $who->id, 'status' => $status, 'price_minor' => 0, 'currency' => 'ZAR']);
        }

        $this->artisan('comms:reminders');

        $this->assertCount(1, $this->inbox($a, 'lesson_reminder'));
        $this->assertCount(1, $this->inbox($b, 'lesson_reminder'));
        $this->assertCount(0, $this->inbox($c, 'lesson_reminder'));
        $this->assertStringContainsString('2 learners', $this->inbox($tutor->user, 'lesson_reminder')->sole()->data['message']);
    }

    public function test_a_reminder_held_past_the_lesson_start_is_not_sent(): void
    {
        $this->configurePush();
        [, $learner, $session] = $this->confirmedLesson(CarbonImmutable::now()->addMinutes(45));
        $this->subscribeBrowser($learner);
        app(QuietHours::class)->save($learner, true, '00:00', '23:59', 'UTC');

        $this->artisan('comms:reminders');
        $push = $this->delivery($learner, 'lesson_reminder', 'push');
        $this->assertSame('quiet_hours', $push->reason);

        $this->travelTo($session->starts_at->copy()->addMinutes(1));
        $push->forceFill(['next_attempt_at' => now()])->save();
        $this->artisan('comms:deliver', ['--limit' => 10]);

        $this->assertSame([NotificationDelivery::SKIPPED, 'expired'], [$push->fresh()->status, $push->fresh()->reason]);
    }

    public function test_assignment_due_reminder_skips_work_already_handed_in(): void
    {
        $tutor = $this->makeTutor()->user;
        $learner = $this->adult('Learner');
        $done = $this->adult('Done');
        $assessment = Assessment::create(['owner_id' => $tutor->id, 'title' => 'Quadratics test', 'kind' => 'tutor_assessment']);

        Assignment::create(['assessment_id' => $assessment->id, 'learner_id' => $learner->id, 'assigned_by' => $tutor->id, 'due_at' => now()->addHours(23)]);
        Assignment::create(['assessment_id' => $assessment->id, 'learner_id' => $done->id, 'assigned_by' => $tutor->id, 'due_at' => now()->addHours(23)]);
        Attempt::create(['assessment_id' => $assessment->id, 'user_id' => $done->id, 'question_order' => [], 'status' => 'submitted', 'started_at' => now(), 'submitted_at' => now()]);

        $this->assertSame('New work from Approved Tutor', $this->inbox($learner, 'assignment_set')->sole()->data['title']);

        $this->artisan('comms:reminders');
        $this->artisan('comms:reminders');

        $this->assertCount(1, $this->inbox($learner, 'assignment_due'));
        $this->assertCount(0, $this->inbox($done, 'assignment_due'));
    }

    public function test_the_calendar_file_names_the_lessons_time_zone(): void
    {
        [, $learner, $session] = $this->confirmedLesson(CarbonImmutable::parse('2026-12-03 13:00:00', 'UTC'), ['timezone' => 'Africa/Johannesburg']);

        $ics = app(LessonCalendar::class)->forSession($session->fresh());

        $this->assertStringContainsString("DTSTART;TZID=Africa/Johannesburg:20261203T150000\r\n", $ics);
        $this->assertStringContainsString("DTEND;TZID=Africa/Johannesburg:20261203T160000\r\n", $ics);
        $this->assertStringContainsString("TZOFFSETTO:+0200\r\n", $ics);
        $this->assertStringContainsString('STATUS:CONFIRMED', $ics);
        $this->assertStringStartsWith("BEGIN:VCALENDAR\r\n", $ics);
        foreach (explode("\r\n", trim($ics)) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line));
        }
        $this->assertStringNotContainsString($learner->email, $ics);
    }

    public function test_a_daylight_saving_zone_gets_both_observances_and_a_cancelled_lesson_says_so(): void
    {
        [, , $session] = $this->confirmedLesson(CarbonImmutable::parse('2026-03-20 10:00:00', 'UTC'), ['timezone' => 'Europe/London']);
        $session->forceFill(['status' => 'cancelled'])->save();

        $ics = app(LessonCalendar::class)->forSession($session->fresh());

        $this->assertStringContainsString('DTSTART;TZID=Europe/London:20260320T100000', $ics);
        $this->assertStringContainsString("BEGIN:DAYLIGHT\r\nDTSTART:20260329T010000\r\nTZOFFSETFROM:+0000\r\nTZOFFSETTO:+0100", $ics);
        $this->assertStringContainsString('STATUS:CANCELLED', $ics);
        $this->assertStringContainsString('SUMMARY:Cancelled: ', $ics);
    }

    public function test_only_people_in_the_booking_can_download_its_calendar_file(): void
    {
        $learner = $this->teen('Sipho');
        $guardian = $this->guardianFor($learner);
        [$tutor, , $session] = $this->confirmedLesson(CarbonImmutable::now()->addDays(3), [], $learner);
        $stranger = $this->adult('Stranger');
        $url = "/comms/lessons/{$session->id}/calendar.ics";

        foreach ([$learner, $tutor->user, $guardian] as $allowed) {
            $this->actingAs($allowed)->get($url)->assertOk()
                ->assertHeader('Content-Type', 'text/calendar; charset=utf-8')
                ->assertHeader('X-Content-Type-Options', 'nosniff');
        }
        $this->actingAs($stranger)->get($url)->assertNotFound();
        $this->actingAs($stranger)->get('/comms/lessons/999999/calendar.ics')->assertNotFound();
    }
}
