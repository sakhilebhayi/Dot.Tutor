<?php

namespace Tests\Feature\Comms;

use App\Domain\Comms\CommsMessage;
use App\Domain\Comms\DeliveryWorker;
use App\Domain\Comms\Inbox;
use App\Domain\Comms\Mail\CommsMail;
use App\Domain\Comms\Models\NotificationDelivery;
use App\Domain\Comms\Models\NotificationPreference;
use App\Domain\Comms\Models\PushSubscription;
use App\Domain\Comms\NotificationRouter;
use App\Domain\Comms\Preferences;
use App\Domain\Comms\Push\PayloadEncrypter;
use App\Domain\Comms\QuietHours;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Channels, preferences, mandatory categories, quiet hours, minors and Dot.Notify. */
class ChannelsAndPreferencesTest extends TestCase
{
    use BuildsComms;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function send(User $to, string $type, string $key = 'k1', array $data = [], ?string $collapse = null, bool $aboutMinor = false): ?string
    {
        return app(NotificationRouter::class)->send(new CommsMessage($to, $type, $key, 'Title '.$key, 'Body '.$key, '/my/bookings', $collapse, data: $data, aboutMinor: $aboutMinor));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function mandatoryCategories(): array
    {
        return [
            'safety' => ['safety', 'account_warned'],
            'payments' => ['payments', 'payment_received'],
            'guardian approvals' => ['guardian', 'guardian_approval_requested'],
        ];
    }

    #[DataProvider('mandatoryCategories')]
    public function test_a_mandatory_category_cannot_be_switched_off(string $category, string $type): void
    {
        $user = $this->adult('Learner');

        $this->actingAs($user)->putJson('/comms/preferences', ['choices' => [['category' => $category, 'channel' => 'email', 'enabled' => false]]])
            ->assertStatus(422)->assertJsonPath('error', 'locked');

        // Even a stored "off" (written around the screen) does not stop it.
        NotificationPreference::create(['user_id' => $user->id, 'category' => $category, 'channel' => 'email', 'enabled' => false]);
        Cache::flush();
        $this->assertTrue(app(Preferences::class)->allows($user, $category, 'email'));

        $this->deliversMail();
        Mail::fake();
        $this->send($user, $type);
        $this->assertSame(NotificationDelivery::SENT, $this->delivery($user, $type, 'email')->status);
        Mail::assertSent(CommsMail::class, fn (CommsMail $m) => $m->mandatory && $m->hasTo($user->email));

        $matrix = $this->actingAs($user)->getJson('/comms/preferences')->assertOk()->json('categories.'.$category);
        $this->assertTrue($matrix['mandatory']);
        $this->assertTrue($matrix['channels']['email']['locked']);
    }

    public function test_switching_a_category_off_skips_that_channel_and_says_why(): void
    {
        $user = $this->adult('Learner');
        $this->deliversMail();
        Mail::fake();

        $this->actingAs($user)->putJson('/comms/preferences', ['choices' => [['category' => 'lessons', 'channel' => 'email', 'enabled' => false]]])
            ->assertOk()->assertJsonPath('categories.lessons.channels.email.enabled', false);

        $this->send($user, 'lesson_confirmed');

        $row = $this->delivery($user, 'lesson_confirmed', 'email');
        $this->assertSame([NotificationDelivery::SKIPPED, 'preference_off'], [$row->status, $row->reason]);
        Mail::assertNothingSent();
        $this->assertCount(1, $this->inbox($user)); // the inbox always has it
        $this->assertSame('Email: off in your settings', $row->toPublicArray()['label']);
    }

    public function test_defaults_apply_until_someone_chooses(): void
    {
        $user = $this->adult('Learner');
        $prefs = app(Preferences::class);

        $this->assertTrue($prefs->allows($user, 'lessons', 'email'));
        $this->assertFalse($prefs->allows($user, 'community', 'email'));
        $this->assertFalse($prefs->allows($user, 'updates', 'email'));
        $this->assertTrue($prefs->allows($user, 'anything', 'in_app'));
    }

    public function test_tutor_categories_are_shown_only_to_tutors(): void
    {
        $learner = $this->adult('Learner');
        $tutor = $this->makeTutor()->user;

        $this->assertArrayNotHasKey('earnings', $this->actingAs($learner)->getJson('/comms/preferences')->json('categories'));
        $this->assertArrayHasKey('earnings', $this->actingAs($tutor)->getJson('/comms/preferences')->json('categories'));
    }

    public function test_email_only_logs_on_this_host_so_the_record_says_not_delivered(): void
    {
        config(['mail.default' => 'log']);
        $user = $this->adult('Learner');

        $this->send($user, 'lesson_confirmed');

        $row = $this->delivery($user, 'lesson_confirmed', 'email');
        $this->assertSame([NotificationDelivery::SKIPPED, 'mail_transport_not_delivering'], [$row->status, $row->reason]);
        $this->assertStringContainsString('not delivered', $row->toPublicArray()['label']);
        $this->actingAs($user)->getJson('/comms/preferences')->assertJsonPath('channels.email.state', 'not_delivering');
    }

    public function test_a_lesson_email_carries_a_calendar_file(): void
    {
        $this->deliversMail();
        Mail::fake();
        [$tutor, $subject] = $this->bookableTutor();
        $learner = $this->adult('Learner');
        $session = $this->makeSession($tutor, $learner, $subject, ['status' => 'confirmed', 'starts_at' => now()->addDays(3)]);

        $this->send($learner, 'lesson_confirmed', 'k-ics', ['ics_session_id' => $session->id]);

        Mail::assertSent(CommsMail::class, function (CommsMail $mail) use ($session) {
            return str_contains((string) $mail->ics, 'UID:tutor-session-'.$session->id.'@') && $mail->icsName === 'dot-tutor-lesson-'.$session->id.'.ics'
                && count($mail->attachments()) === 1;
        });
        $this->assertTrue($this->delivery($learner, 'lesson_confirmed', 'email')->meta['calendar_attached']);
    }

    public function test_the_same_event_reaches_a_person_once(): void
    {
        $user = $this->adult('Learner');

        $this->assertNotNull($this->send($user, 'lesson_confirmed', 'same'));
        $this->assertNull($this->send($user, 'lesson_confirmed', 'same'));
        $this->assertCount(1, $this->inbox($user));
        $this->assertSame(1, NotificationDelivery::where('user_id', $user->id)->where('channel', 'email')->count());
    }

    public function test_the_unread_count_is_one_per_subject_and_the_badge_is_capped(): void
    {
        $user = $this->adult('Learner');
        foreach (range(1, 4) as $i) {
            $this->send($user, 'lesson_changed', 'b'.$i, collapse: 'booking-7');
        }
        $this->assertSame(1, Inbox::unreadCount($user));

        foreach (range(1, 11) as $i) {
            $this->send($user, 'general', 'g'.$i);
        }
        $this->assertSame(12, Inbox::unreadCount($user));
        $this->actingAs($user)->getJson('/comms/inbox/summary')->assertJsonPath('badge', '9+')->assertJsonPath('unread', 12);

        // Opening one update about the booking reads the others about it.
        $first = $this->inbox($user, 'lesson_changed')->first();
        $this->actingAs($user)->postJson("/comms/inbox/{$first->id}/read")->assertOk()->assertJsonPath('unread', 11);
        $this->assertSame(0, $user->unreadNotifications()->get()->filter(fn ($n) => ($n->data['collapse_key'] ?? null) === 'booking-7')->count());
    }

    public function test_nobody_can_read_or_mark_someone_elses_notification(): void
    {
        $owner = $this->adult('Owner');
        $other = $this->adult('Other');
        $id = $this->send($owner, 'general');

        $this->actingAs($other)->postJson("/comms/inbox/{$id}/read")->assertNotFound()->assertJsonPath('error', 'not_found');
        $this->assertSame([], $this->actingAs($other)->getJson('/comms/inbox')->json('items'));
        $this->assertCount(1, $this->actingAs($owner)->getJson('/comms/inbox')->json('items'));
    }

    public function test_marketing_never_reaches_a_minor_and_cannot_be_turned_on_for_one(): void
    {
        $teen = $this->teen('Teen');
        $adult = $this->adult('Adult');

        $this->assertNull($this->send($teen, 'product_update'));
        $this->assertCount(0, $this->inbox($teen));
        $this->assertNotNull($this->send($adult, 'product_update'));

        $this->actingAs($teen)->putJson('/comms/preferences', ['choices' => [['category' => 'updates', 'channel' => 'email', 'enabled' => true]]])
            ->assertStatus(422);
        $this->assertArrayNotHasKey('updates', $this->actingAs($teen)->getJson('/comms/preferences')->json('categories'));
    }

    public function test_a_child_gets_notices_in_the_app_only(): void
    {
        $this->deliversMail();
        Mail::fake();
        $child = $this->child('Ayanda');

        $this->send($child, 'lesson_confirmed');

        $this->assertCount(1, $this->inbox($child));
        $this->assertSame('child_in_app_only', $this->delivery($child, 'lesson_confirmed', 'email')->reason);
        Mail::assertNothingSent();
    }

    public function test_quiet_hours_hold_push_until_they_end_but_not_email_or_safety(): void
    {
        $this->configurePush();
        $this->deliversMail();
        Mail::fake();
        Http::fake(['fcm.googleapis.com/*' => Http::response('', 201)]);
        $user = $this->adult('Learner');
        $this->subscribeBrowser($user);
        $this->actingAs($user)->putJson('/comms/preferences', ['quiet_hours' => ['enabled' => true, 'start' => '21:00', 'end' => '07:00', 'timezone' => 'Africa/Johannesburg']])->assertOk();

        $this->travelTo(CarbonImmutable::parse('2026-11-12 22:30', 'Africa/Johannesburg'));
        $this->send($user, 'lesson_confirmed', 'night');
        $this->send($user, 'account_warned', 'night-safety');

        $push = $this->delivery($user, 'lesson_confirmed', 'push');
        $this->assertSame([NotificationDelivery::QUEUED, 'quiet_hours'], [$push->status, $push->reason]);
        $this->assertTrue($push->next_attempt_at->equalTo(CarbonImmutable::parse('2026-11-13 07:00', 'Africa/Johannesburg')));
        $this->assertSame('Push: waiting until your quiet hours end', $push->toPublicArray()['label']);
        $this->assertSame(NotificationDelivery::SENT, $this->delivery($user, 'lesson_confirmed', 'email')->status);
        $this->assertSame(NotificationDelivery::SENT, $this->delivery($user, 'account_warned', 'push')->status, 'safety is urgent');

        app(DeliveryWorker::class)->deliverDue();
        $this->assertSame(NotificationDelivery::QUEUED, $push->fresh()->status, 'still quiet');

        $this->travelTo(CarbonImmutable::parse('2026-11-13 07:01', 'Africa/Johannesburg'));
        app(DeliveryWorker::class)->deliverDue();
        $this->assertSame(NotificationDelivery::SENT, $push->fresh()->status);
    }

    public function test_quiet_hours_can_be_switched_off_and_bad_times_are_refused(): void
    {
        $user = $this->adult('Learner');

        $this->actingAs($user)->putJson('/comms/preferences', ['quiet_hours' => ['enabled' => true, 'start' => '25:00', 'end' => '07:00']])->assertStatus(422);
        $this->actingAs($user)->putJson('/comms/preferences', ['quiet_hours' => ['enabled' => true, 'start' => '22:00', 'end' => '06:00', 'timezone' => 'Mars/Base']])->assertStatus(422);
        $this->actingAs($user)->putJson('/comms/preferences', ['quiet_hours' => ['enabled' => false, 'start' => '22:00', 'end' => '06:00']])
            ->assertOk()->assertJsonPath('quiet_hours.enabled', false);

        $this->assertNull(app(QuietHours::class)->deferUntil($user, CarbonImmutable::parse('2026-11-12 23:00')));
    }

    public function test_a_push_is_encrypted_for_the_device_and_a_gone_device_is_dropped(): void
    {
        $this->configurePush();
        $user = $this->adult('Learner');
        $device = $this->subscribeBrowser($user);
        $this->subscribeBrowser($user, 'https://updates.push.services.mozilla.com/wpush/v2/gone');
        app(QuietHours::class)->save($user, false, '21:00', '07:00', null);
        $bodies = [];
        Http::fake(function (HttpRequest $request) use (&$bodies) {
            if (str_contains($request->url(), 'gone')) {
                return Http::response('', 410);
            }
            $bodies[] = $request;

            return Http::response('', 201, ['Location' => 'https://fcm.googleapis.com/fcm/send/abc123/msg-1']);
        });

        $this->send($user, 'lesson_confirmed', 'push-1');

        $row = $this->delivery($user, 'lesson_confirmed', 'push');
        $this->assertSame(NotificationDelivery::SENT, $row->status);
        $this->assertSame('msg-1', $row->provider_id);
        $this->assertCount(1, $bodies);
        $this->assertStringStartsWith('vapid t=', $bodies[0]->header('Authorization')[0]);
        $plain = app(PayloadEncrypter::class)->decrypt($bodies[0]->body(), $device['ua']['private'], $device['ua']['public'], $device['auth']);
        $this->assertSame('Title push-1', json_decode($plain, true)['title']);
        $this->assertSame(1, PushSubscription::active()->where('user_id', $user->id)->count(), 'the 410 device is revoked');
    }

    public function test_push_is_off_without_keys_and_says_so(): void
    {
        $user = $this->adult('Learner');
        $this->send($user, 'lesson_confirmed');

        $this->assertSame('push_not_configured', $this->delivery($user, 'lesson_confirmed', 'push')->reason);
        $this->actingAs($user)->postJson('/comms/push/subscriptions', ['endpoint' => 'https://fcm.googleapis.com/x', 'keys' => ['p256dh' => 'a', 'auth' => 'b']])
            ->assertStatus(503)->assertJsonPath('error', 'push_not_configured');
        $this->actingAs($user)->getJson('/comms/preferences')->assertJsonPath('channels.push.state', 'not_configured');
    }

    public function test_a_subscription_to_an_unknown_push_service_is_refused(): void
    {
        $this->configurePush();
        $user = $this->adult('Learner');
        $this->actingAs($user)->postJson('/comms/push/subscriptions', ['endpoint' => 'https://evil.example/push', 'keys' => ['p256dh' => 'a', 'auth' => 'b']])
            ->assertStatus(422)->assertJsonPath('error', 'subscription_refused');
    }

    public function test_sms_is_not_configured_and_nobody_is_asked_for_a_number(): void
    {
        $user = $this->adult('Learner');
        $this->send($user, 'account_warned');

        $this->assertSame('sms_not_configured', $this->delivery($user, 'account_warned', 'sms')->reason);
        $this->assertNull($this->delivery($user, 'account_warned', 'sms')->sent_at);
        $this->assertNull($this->delivery($user, 'lesson_confirmed', 'sms'));
        $this->actingAs($user)->getJson('/comms/preferences')->assertJsonPath('channels.sms.state', 'not_configured')
            ->assertJsonMissingPath('categories.safety.channels.sms');
    }

    public function test_dot_notify_is_off_by_default(): void
    {
        $user = $this->adult('Learner');
        $this->send($user, 'lesson_confirmed');

        $this->assertNull($this->delivery($user, 'lesson_confirmed', 'notify'));
        Http::assertNothingSent();
    }

    public function test_dot_notify_receives_a_signed_event_for_an_adult_and_nothing_about_a_minor(): void
    {
        config(['notify.url' => 'https://notify.infodot.test/api/ingest/events', 'notify.secret' => 'shh']);
        Http::fake(['notify.infodot.test/*' => Http::response(['id' => 9, 'status' => 'no_rule'], 202)]);
        $adult = $this->adult('Adult');
        $adult->personalTeam()->forceFill(['infodot_team_uuid' => (string) Str::uuid()])->save();
        $teen = $this->teen('Teen');
        $teen->personalTeam()->forceFill(['infodot_team_uuid' => (string) Str::uuid()])->save();

        $this->send($adult, 'lesson_confirmed', 'n1');
        $this->send($teen, 'lesson_confirmed', 'n2');
        $this->send($adult, 'lesson_confirmed', 'n3', aboutMinor: true);

        $row = $this->delivery($adult, 'lesson_confirmed', 'notify');
        $this->assertSame(NotificationDelivery::SENT, $row->status);
        $this->assertSame('no_rule', $row->meta['notify_status']);
        $this->assertStringContainsString('no rule there', $row->toPublicArray()['label']);
        $this->assertNull($this->delivery($teen, 'lesson_confirmed', 'notify'));
        $this->assertSame(1, NotificationDelivery::where('channel', 'notify')->count());

        Http::assertSent(function (HttpRequest $request) {
            $timestamp = $request->header('X-InfoDot-Timestamp')[0];
            $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->body(), 'shh');

            return $request['platform'] === 'tutor' && $request['event'] === 'tutor.lesson.confirmed'
                && $request->header('X-InfoDot-Signature')[0] === $expected && ! isset($request['data']['message']);
        });
    }
}
