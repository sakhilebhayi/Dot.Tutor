<?php

namespace Tests\Feature\Comms;

use App\Domain\Comms\CommsMessage;
use App\Domain\Comms\NotificationRouter;
use App\Domain\Comms\Preferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The inbox and settings pages: real content, honest channel states, forms that work without script. */
class CommsPagesTest extends TestCase
{
    use BuildsComms;
    use RefreshDatabase;

    public function test_an_empty_inbox_says_so(): void
    {
        $this->actingAs($this->adult('Learner'))->get('/comms/inbox')
            ->assertOk()->assertSee('No notifications yet')->assertSee('Notification settings');
    }

    public function test_the_inbox_lists_real_notifications_with_their_delivery_outcome_and_opens_them(): void
    {
        $user = $this->adult('Learner');
        $id = app(NotificationRouter::class)->send(new CommsMessage($user, 'lesson_confirmed', 'p1', 'Lesson confirmed with Ms Khumalo', 'Your Algebra lesson is on Thu.', '/my/bookings'));

        $this->actingAs($user)->get('/comms/inbox')->assertOk()
            ->assertSee('Lesson confirmed with Ms Khumalo')
            ->assertSee('Unread')
            ->assertSee('Email: not delivered (Dot.Tutor only logs email at the moment)')
            ->assertSee('Push: not set up on Dot.Tutor yet');

        $this->actingAs($user)->post("/comms/inbox/{$id}/read", ['open' => 1])->assertRedirect('/my/bookings');
        $this->assertNotNull($user->notifications()->first()->read_at);
        $this->actingAs($user)->get('/comms/inbox?unread=1')->assertSee('Nothing unread');
    }

    public function test_the_page_sits_in_the_persons_own_shell(): void
    {
        $tutor = $this->makeTutor()->user;
        $this->actingAs($tutor)->get('/comms/inbox')->assertOk()->assertSee('td-app--workspace', false);
        $this->actingAs($this->adult('Learner'))->get('/comms/inbox')->assertOk()->assertSee('td-app--learner', false);
    }

    public function test_settings_show_mandatory_kinds_as_facts_and_save_from_a_plain_form(): void
    {
        $user = $this->adult('Learner');
        $page = $this->actingAs($user)->get('/comms/preferences')->assertOk()
            ->assertSee('Always on')
            ->assertSee('Safety messages always reach you')
            ->assertSee('Text messages (SMS) are not available on Dot.Tutor.')
            ->assertSee('Push notifications are not set up on Dot.Tutor yet.');
        $this->assertStringNotContainsString('value="safety.email"', $page->getContent());
        $this->assertStringContainsString('value="lessons.email"', $page->getContent());

        // Lessons email unticked, push ticked; quiet hours changed.
        $this->actingAs($user)->from('/comms/preferences')->put('/comms/preferences', [
            'shown' => ['lessons.email', 'lessons.push', 'community.push'],
            'on' => ['lessons.push'],
            'quiet_hours' => ['enabled' => '1', 'start' => '22:00', 'end' => '06:30', 'timezone' => 'Africa/Johannesburg'],
        ])->assertRedirect('/comms/preferences')->assertSessionHas('success');

        $prefs = app(Preferences::class);
        $this->assertFalse($prefs->allows($user, 'lessons', 'email'));
        $this->assertTrue($prefs->allows($user, 'lessons', 'push'));
        $this->assertFalse($prefs->allows($user, 'community', 'push'));
        $this->assertSame('22:00', app(\App\Domain\Comms\QuietHours::class)->settings($user)['start']);
    }

    public function test_a_teen_is_told_their_guardian_gets_copies(): void
    {
        $this->actingAs($this->teen('Teen'))->get('/comms/preferences')->assertOk()
            ->assertSee('your parent or guardian also gets copies')
            ->assertDontSee('News from Dot.Tutor');
    }

    public function test_push_controls_appear_only_when_push_is_configured(): void
    {
        $user = $this->adult('Learner');
        $this->actingAs($user)->get('/comms/preferences')->assertDontSee('data-comms-push', false);

        $this->configurePush();
        $this->actingAs($user)->get('/comms/preferences')->assertSee('data-comms-push', false)->assertSee('Turn on push here');
        $this->actingAs($user)->getJson('/comms/push/key')->assertJsonPath('configured', true);
    }

    public function test_the_bell_shows_the_unread_count_per_subject(): void
    {
        $user = $this->adult('Learner');
        $this->actingAs($user);
        $this->assertStringContainsString('Notifications, none unread', \Illuminate\Support\Facades\Blade::render('<x-comms::bell />'));

        foreach (range(1, 3) as $i) {
            app(NotificationRouter::class)->send(new CommsMessage($user, 'lesson_changed', 'bell'.$i, 'T', 'B', null, 'booking-1'));
        }
        $this->assertStringContainsString('Notifications, 1 unread', \Illuminate\Support\Facades\Blade::render('<x-comms::bell />'));
    }
}
