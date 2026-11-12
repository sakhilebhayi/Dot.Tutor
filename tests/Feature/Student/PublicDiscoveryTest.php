<?php

namespace Tests\Feature\Student;

use App\Models\TutorProfile;
use App\Models\TutorSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Acceptance\BuildsJourneys;
use Tests\TestCase;

/**
 * R-47 polish (D-2003): a signed-out visitor can search approved tutors and read a storefront. Contact details in
 * tutor free text stay hidden, unapproved tutors are not found, and booking, reporting and the plain-words reader
 * still need sign-in.
 */
class PublicDiscoveryTest extends TestCase
{
    use BuildsJourneys;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorld();
    }

    public function test_a_guest_reads_an_approved_storefront_without_contact_details_and_is_sent_to_sign_in_to_book(): void
    {
        [$tutorUser, $profile, $offeringId] = $this->tutorReadyToTeach('Thandeka Mokoena');
        $profile->forceFill([
            'bio' => 'Twenty years of maths. WhatsApp me on 082 123 4567.',
            'headline' => 'CAPS Maths, call 082 765 4321',
            'public_handle' => 'thandeka-maths',
        ])->save();
        $this->app['auth']->forgetGuards();

        $page = $this->get(route('marketplace.tutors.show', $profile))->assertOk()
            ->assertSee('Thandeka Mokoena')->assertSee('Grade 11 Maths lesson')
            ->assertSee('Sign in to request this lesson')->assertSee('contact details are never shown here')
            ->assertDontSee('082 123 4567')->assertDontSee('082 765 4321')
            ->assertDontSee('td-app--learner', false)->assertSee('td-public', false)
            ->assertSee('<meta name="description"', false);
        $this->assertStringNotContainsString(route('safety.reports.store'), $page->getContent(), 'reporting needs an account');

        // The short link opens it too, and the booking button sends the guest to sign in and back.
        $this->get('/u/thandeka-maths')->assertRedirect(route('marketplace.tutors.show', $profile));
        $this->get(route('learn.book', $offeringId))->assertRedirect('/login');
        $this->assertSame(route('learn.book', $offeringId), session('url.intended'));
        $this->post(route('learn.book.store', $offeringId), ['subject_id' => $this->subject('mathematics')->id, 'starts_at' => now()->addDays(3)->toIso8601String()])
            ->assertRedirect('/login');
        $this->assertSame(0, TutorSession::query()->count());

        // The signed-in adult learner still reads the tutor's own words.
        $adult = $this->person('Adult Reader');
        $this->learnerProfile($adult, 30);
        $this->actingAs($adult)->get(route('marketplace.tutors.show', $profile))->assertOk()->assertSee('082 123 4567')->assertSee('Request this lesson');
    }

    public function test_an_unapproved_tutor_is_not_public(): void
    {
        $user = $this->person('Pending Tutor');
        $profile = TutorProfile::query()->create(['user_id' => $user->id, 'status' => 'pending', 'headline' => 'Pending', 'bio' => 'x', 'hourly_rate' => 100, 'timezone' => 'Africa/Johannesburg']);

        $this->get(route('marketplace.tutors.show', $profile))->assertNotFound();
        $this->get(route('marketplace.tutors.photo', $profile))->assertNotFound();
    }

    public function test_a_guest_searches_approved_tutors_and_the_search_is_rate_limited(): void
    {
        [, $profile] = $this->tutorReadyToTeach('Thandeka Mokoena');
        $profile->forceFill(['headline' => 'CAPS Maths, call 082 765 4321'])->save();
        $this->app['auth']->forgetGuards();

        $this->get(route('marketplace.find'))->assertOk()->assertSee('Looking without an account')
            ->assertDontSee(route('learn.need'), false)->assertSee('Choose a subject to see tutors');

        $this->get(route('match.search', ['subject_id' => $this->subject('mathematics')->id, 'level_id' => $this->level('za-caps', 'grade-11')->id]))
            ->assertRedirect();
        $this->get(route('marketplace.find', ['subject_id' => $this->subject('mathematics')->id]))->assertOk()
            ->assertSee('Thandeka Mokoena')->assertSee('See profile and prices')->assertDontSee('082 765 4321');

        // The plain-words reader stays behind sign-in.
        $this->post(route('learn.need'), ['need' => 'Grade 11 maths'])->assertRedirect('/login');

        config(['tutor.public.search_per_minute' => 3]);
        $codes = [];
        foreach (range(1, 6) as $i) {
            $codes[] = $this->get(route('marketplace.find', ['subject_id' => $this->subject('mathematics')->id]))->getStatusCode();
        }
        $this->assertContains(429, $codes, 'guest searches are limited per address');
    }
}
