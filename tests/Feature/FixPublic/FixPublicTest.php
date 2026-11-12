<?php

namespace Tests\Feature\FixPublic;

use App\Domain\Comms\CommsMessage;
use App\Domain\Comms\NotificationRouter;
use App\Domain\Integration\Support\TutorStanding;
use App\Domain\Marketplace\Models\TutorQualification;
use App\Domain\Student\Support\BookingWindow;
use App\Support\InfoDotSignIn;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Feature\Acceptance\BuildsJourneys;
use Tests\TestCase;

/** Fix round after jury round 2, public and learner screens (docs/R47_FIXES.md, fix-public, D-2300 to D-2311). */
class FixPublicTest extends TestCase
{
    use BuildsJourneys;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorld();
    }

    public function test_the_public_header_has_a_menu_button_for_phones_on_every_public_page(): void
    {
        foreach (['/', '/login', '/terms-of-service', '/no-such-page-here'] as $path) {
            $html = $this->get($path)->getContent();
            $this->assertMatchesRegularExpression('/<button type="button" class="td-btn td-btn--quiet td-public-menu" data-public-menu aria-controls="td-public-nav" aria-expanded="false" hidden>/', $html, $path);
            $this->assertStringContainsString('<nav id="td-public-nav" class="td-public-nav" aria-label="Dot.Tutor">', $html, $path);
            $this->assertStringNotContainsString('onclick=', $html, $path.': no inline handler (CSP)');
        }
    }

    public function test_sign_in_offers_infodot_only_when_the_launch_page_and_secret_are_configured(): void
    {
        config(['ecosystem.infodot.launch_url' => null, 'ecosystem.infodot.secret' => 'shh']);
        $this->get('/login')->assertOk()->assertDontSee('Continue with InfoDot')
            ->assertSee('New here?')->assertSee('Dot.Tutor accounts come from InfoDot');

        config(['ecosystem.infodot.launch_url' => 'https://infodot.example.test/go/tutor', 'ecosystem.infodot.secret' => null]);
        $this->get('/login')->assertDontSee('Continue with InfoDot');

        config(['ecosystem.infodot.secret' => 'shh']);
        $this->get('/login')->assertSee('Continue with InfoDot')->assertSee('href="https://infodot.example.test/go/tutor"', false)
            ->assertSee('Or sign in with a Dot.Tutor password')->assertSee('the first time you continue with InfoDot');

        config(['ecosystem.infodot.launch_url' => 'javascript:alert(1)']);
        $this->assertNull(InfoDotSignIn::launchUrl());
        config(['ecosystem.infodot.launch_url' => 'http://localhost:9000/go/tutor']);
        $this->assertSame('http://localhost:9000/go/tutor', InfoDotSignIn::launchUrl());
        $this->app['env'] = 'production';
        $this->assertNull(InfoDotSignIn::launchUrl(), 'plain http never in production');
    }

    public function test_forgot_password_names_its_action_plainly(): void
    {
        $this->get('/forgot-password')->assertOk()->assertSee('Email me a reset link')
            ->assertDontSee('Email Password Reset Link')->assertDontSee('No problem');
    }

    public function test_a_verified_record_without_a_level_reads_as_one_status_everywhere(): void
    {
        [, $profile] = $this->tutorReadyToTeach('Thandeka Mokoena');
        TutorQualification::create(['tutor_profile_id' => $profile->id, 'kind' => 'degree', 'title' => 'BSc Mathematics'])
            ->forceFill(['status' => 'verified', 'reviewed_at' => now()])->save();

        $this->get(route('marketplace.tutors.show', $profile->id))->assertOk()
            ->assertSee('Qualification checked, level pending')->assertSee('Verified by Dot.Tutor')
            ->assertDontSee('Not yet verified')->assertDontSee('has not reviewed evidence for this tutor yet');

        $facts = collect(app(TutorStanding::class)->facts($profile->fresh()))->keyBy('key');
        $this->assertSame('Qualification checked, level pending', $facts['qualification']['text']);
        $this->assertNull($facts['qualification']['ok']);
    }

    public function test_booking_opens_on_the_learners_window_and_groups_times(): void
    {
        [, , $offeringId] = $this->tutorReadyToTeach('Thandeka Mokoena');
        $learner = $this->person('Sipho Dlamini');
        $this->learnerProfile($learner, 19);

        $html = $this->actingAs($learner)->get(route('learn.book', ['offering' => $offeringId, 'weekdays' => '1,2,3,4,5', 'from_time' => '14:00', 'to_time' => '18:00']))
            ->assertOk()->assertSee('Your times')->assertSee('Weekdays 14:00 to 18:00')->assertSee('Show all times')->getContent();
        preg_match_all('/<span>(\d\d:\d\d)<\/span>/', $html, $clocks);
        $this->assertNotEmpty($clocks[1]);
        foreach ($clocks[1] as $clock) {
            $this->assertTrue($clock >= '14:00' && $clock < '18:00', "{$clock} is inside the window");
        }
        $this->assertStringContainsString('st-times-group__label">Afternoon', $html);
        $this->assertStringNotContainsString('st-times-group__label">Morning', $html);

        $all = $this->actingAs($learner)->get(route('learn.book', ['offering' => $offeringId, 'weekdays' => '1,2,3,4,5', 'from_time' => '14:00', 'to_time' => '18:00', 'all' => 1]))
            ->assertOk()->assertSee('Showing every open time')->assertSee('Only Weekdays 14:00 to 18:00')->getContent();
        $this->assertStringContainsString('st-times-group__label">Morning', $all);

        // No window and an adult: every time, no window line.
        $this->actingAs($learner)->get(route('learn.book', $offeringId))->assertOk()->assertDontSee('Show all times')->assertDontSee('Your times');
    }

    public function test_a_young_learner_starts_on_after_school_times_and_a_guardian_pays(): void
    {
        [, , $offeringId] = $this->tutorReadyToTeach('Thandeka Mokoena');
        $child = $this->person('Lerato Young');
        $this->learnerProfile($child, 10, 'za-caps', 'grade-5', ['zu']);

        $this->actingAs($child)->get(route('learn.book', $offeringId))->assertOk()
            ->assertSee('After-school times')->assertSee('What your parent or guardian pays')->assertDontSee('What you pay');

        $find = $this->actingAs($child)->get(route('marketplace.find'))->assertOk()
            ->assertSee('placeholder="Maths, Grade 5"', false)->assertDontSee('Grade 11 Maths in isiZulu, after school, under R250')
            ->assertSee('More choices')->getContent();
        // Budget and times wait behind "More choices".
        $this->assertLessThan(strpos($find, 'name="budget_rand"'), strpos($find, 'More choices'));
        $this->assertLessThan(strpos($find, 'name="from_time"'), strpos($find, 'More choices'));
    }

    public function test_booking_window_rules(): void
    {
        $window = BookingWindow::fromRequest(Request::create('/', 'GET', ['weekdays' => '5,1,2,3,4', 'from_time' => '14:00', 'to_time' => '18:00']), false);
        $this->assertSame('Weekdays 14:00 to 18:00', $window->label());
        $friday = CarbonImmutable::parse('2026-11-13 14:00', 'Africa/Johannesburg');
        $this->assertTrue($window->contains($friday));
        $this->assertFalse($window->contains($friday->setTime(18, 0)), 'the end is not a start time');
        $this->assertFalse($window->contains($friday->addDay()), 'Saturday is outside');
        $this->assertNull(BookingWindow::fromRequest(Request::create('/', 'GET', ['from_time' => '25:00']), false));
        $this->assertSame('after_school', BookingWindow::fromRequest(Request::create('/'), true)->source);
        $this->assertSame(['Morning', 'Afternoon', 'Evening'], [
            BookingWindow::period($friday->setTime(11, 30)), BookingWindow::period($friday->setTime(12, 0)), BookingWindow::period($friday->setTime(17, 0)),
        ]);
    }

    public function test_next_open_times_link_into_booking_with_the_time_chosen(): void
    {
        [, $profile, $offeringId] = $this->tutorReadyToTeach('Thandeka Mokoena');
        $learner = $this->person('Sipho Dlamini');
        $this->learnerProfile($learner, 19);

        $html = $this->actingAs($learner)->get(route('marketplace.find', [
            'subject_id' => $this->subject('mathematics')->id, 'level_id' => $this->level('za-caps', 'grade-11')->id,
            'weekdays' => [1, 2, 3, 4, 5], 'from_time' => '14:00', 'to_time' => '18:00',
        ]))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#class="st-slotchip" href="[^"]*/learn/book/'.$offeringId.'\?at=[^"]*from_time=14%3A00#', $html);

        $store = $this->actingAs($learner)->get(route('marketplace.tutors.show', $profile->id))->assertOk()->getContent();
        preg_match('#class="st-slotchip" href="([^"]+)"#', $store, $m);
        $this->assertNotEmpty($m, 'the storefront times are links');
        $at = urldecode(html_entity_decode((string) parse_url(html_entity_decode($m[1]), PHP_URL_QUERY)));
        $chosen = substr($at, 3);
        $booking = $this->actingAs($learner)->get(html_entity_decode($m[1]))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#value="'.preg_quote($chosen, '#').'" checked#', $booking, 'the time arrives chosen');
    }

    public function test_notification_markers_follow_the_per_subject_unread_count(): void
    {
        $user = $this->person('Inbox Learner');
        $router = app(NotificationRouter::class);
        $router->send(new CommsMessage($user, 'lesson_requested', 'e1', 'Lesson requested', 'Waiting for the tutor.', '/my/bookings', collapseKey: 'booking-1'));
        $router->send(new CommsMessage($user, 'lesson_confirmed', 'e2', 'Lesson confirmed with Ms Khumalo', 'Thu 16:00.', '/my/bookings', collapseKey: 'booking-1'));
        $router->send(new CommsMessage($user, 'lesson_confirmed', 'e3', 'Another lesson confirmed', 'Fri 15:00.', '/my/bookings', collapseKey: 'booking-2'));

        $html = $this->actingAs($user)->get('/comms/inbox')->assertOk()->getContent();
        $this->assertSame(2, substr_count($html, 'aria-hidden="true"></span>Unread'), 'two subjects, two markers');
        $this->assertSame(1, substr_count($html, 'Earlier update'));
        $this->assertStringContainsString('<span class="comms-item__tail">Khumalo<svg', $html, 'the arrow is joined to the last word');
    }
}
