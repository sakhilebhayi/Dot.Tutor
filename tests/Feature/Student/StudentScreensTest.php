<?php

namespace Tests\Feature\Student;

use App\Domain\Academic\Contracts\LanguageProfile;
use App\Domain\Academic\Models\Country;
use App\Domain\Learning\Models\FlashcardSet;
use App\Domain\Student\Models\StudentPreference;
use App\Models\TutorSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Acceptance\BuildsJourneys;
use Tests\TestCase;

/**
 * R-47 student UI (docs/R47_UI_STUDENT.md): every learner screen renders in the learner shell with real data, the
 * reading step follows the level and age band, and every action goes through the owning domain's rules.
 */
class StudentScreensTest extends TestCase
{
    use BuildsJourneys;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorld();
    }

    public function test_guests_are_sent_to_sign_in(): void
    {
        foreach (['/learn', '/learn/welcome', '/learn/practice', '/learn/progress', '/learn/account', '/learn/purchases', '/learn/courses'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
    }

    public function test_home_asks_a_new_learner_for_their_level_and_shows_the_need_field(): void
    {
        $learner = $this->person('Naledi First');

        $this->actingAs($learner)->get(route('learn.home'))->assertOk()
            ->assertSee('td-app--learner', false)
            ->assertSee('Tell us about your learning')
            ->assertSee('Tell us what you need')
            ->assertSee('No lessons booked')
            ->assertDontSee('data-td-step="young"', false);
    }

    public function test_a_grade_3_learner_gets_the_young_step_and_a_university_learner_does_not(): void
    {
        $child = $this->person('Lerato Young');
        $this->learnerProfile($child, 8, 'za-caps', 'grade-3', ['zu']);
        $student = $this->person('Thabo University');
        $this->learnerProfile($student, 20, 'za-higher-education', 'undergraduate-1', ['en']);

        $young = $this->actingAs($child)->get(route('learn.home'))->assertOk();
        $young->assertSee('data-td-step="young"', false)->assertSee('Get help')->assertSee('Hi Lerato')
            ->assertSee('A parent or guardian needs to join you first');
        $this->assertStringNotContainsString('>Courses<', preg_replace('/\s+/', '', strip_tags($young->getContent(), '<a>')) ?? '');

        $this->actingAs($student)->get(route('learn.home'))->assertOk()
            ->assertDontSee('data-td-step="young"', false)->assertSee('Your learning')->assertSee('Undergraduate year 1');

        // The learner can override it either way.
        $this->actingAs($student)->put(route('learn.account.step'), ['step' => 'young'])->assertRedirect(route('learn.account'));
        $this->assertSame('young', StudentPreference::query()->where('user_id', $student->id)->value('step'));
        $this->actingAs($student)->get(route('learn.home'))->assertSee('data-td-step="young"', false);
        $this->actingAs($child)->put(route('learn.account.step'), ['step' => 'standard']);
        $this->actingAs($child)->get(route('learn.home'))->assertDontSee('data-td-step="young"', false);
        $this->actingAs($child)->put(route('learn.account.step'), ['step' => 'tiny'])->assertSessionHasErrors('step');
    }

    public function test_onboarding_saves_the_profile_and_interface_language_and_sends_a_minor_to_the_guardian_step(): void
    {
        $teen = $this->person('Ayanda Teen');
        $level = $this->level('za-caps', 'grade-10');

        $this->actingAs($teen)->get(route('learn.onboarding', ['level' => $level->id]))->assertOk()
            ->assertSee('Let\'s get you the right help')->assertSee('Language of this website')->assertSee('Skip for now');

        $born = now()->subYears(15);
        $this->actingAs($teen)->post(route('learn.onboarding.store'), [
            'country_id' => Country::query()->where('code', 'ZA')->value('id'),
            'academic_level_id' => $level->id,
            'subjects' => [$this->subject('mathematics')->id],
            'birth_year' => $born->year, 'birth_month' => $born->month,
            'teaching' => ['zu'], 'bilingual_ok' => '1', 'interface_locale' => 'af',
        ])->assertRedirect(route('learn.onboarding', ['step' => 'guardian']));

        $this->actingAs($teen)->get(route('learn.onboarding', ['step' => 'guardian']))->assertOk()
            ->assertSee('One more step: a parent or guardian')->assertSee(route('safety.guardians.invite'), false);
        $this->assertSame('af', app(LanguageProfile::class)->interfaceLocale($teen));

        $adult = $this->person('Pieter Adult');
        $this->actingAs($adult)->post(route('learn.onboarding.store'), [
            'country_id' => Country::query()->where('code', 'ZA')->value('id'),
            'academic_level_id' => $this->level('za-higher-education', 'undergraduate-2')->id,
            'birth_year' => now()->subYears(21)->year, 'birth_month' => 3,
        ])->assertRedirect(route('learn.home'));

        // The month of birth is the one required personal fact; it is refused when missing.
        $this->actingAs($this->person('No Birth'))->post(route('learn.onboarding.store'), [
            'country_id' => Country::query()->where('code', 'ZA')->value('id'), 'academic_level_id' => $level->id,
        ])->assertSessionHasErrors(['birth_month', 'birth_year']);
    }

    public function test_plain_words_become_explained_matches(): void
    {
        [, $profile] = $this->tutorReadyToTeach('Thandeka Mokoena');
        $learner = $this->person('Sipho Dlamini');
        $this->learnerProfile($learner, 19);

        $redirect = $this->actingAs($learner)->post(route('learn.need'), ['need' => 'Grade 11 Maths in isiZulu, online, under R300'])->assertRedirect();
        $target = $redirect->headers->get('Location');
        $this->assertStringContainsString('/find-a-tutor?', $target);
        parse_str((string) parse_url($target, PHP_URL_QUERY), $query);
        $this->assertSame((string) $this->subject('mathematics')->id, (string) $query['subject_id']);
        $this->assertSame((string) $this->level('za-caps', 'grade-11')->id, (string) $query['level_id']);
        $this->assertSame(['zu'], $query['languages']);
        $this->assertSame('300', (string) $query['budget_rand']);
        $this->assertSame('online', $query['format']);

        $page = $this->actingAs($learner)->get($target)->assertOk();
        $page->assertSee('What we understood')->assertSee('Thandeka Mokoena')->assertSee('1 tutor fits')
            ->assertSee('Teaches in isiZulu')->assertSee('Within your budget')->assertSee('How this was ranked')
            ->assertSee('td-chip--match', false)->assertSee('See profile and book');

        // An unknown subject is said plainly, not guessed.
        $this->actingAs($learner)->followingRedirects()->post(route('learn.need'), ['need' => 'help please'])
            ->assertOk()->assertSee('Which subject?');
    }

    public function test_the_filter_form_fills_the_curriculum_from_the_level(): void
    {
        $learner = $this->person('Filter Learner');
        $level = $this->level('za-caps', 'grade-11');
        $response = $this->actingAs($learner)->get(route('match.search', [
            'subject_id' => $this->subject('mathematics')->id, 'level_id' => $level->id, 'format' => 'either', 'area' => '', 'languages' => ['zu', ''],
        ]))->assertRedirect();
        parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame((string) $level->curriculum_id, (string) $query['curriculum_id']);
        $this->assertArrayNotHasKey('format', $query);
        $this->assertArrayNotHasKey('area', $query);
        $this->assertSame(['zu'], $query['languages']);
    }

    public function test_the_find_page_accepts_lists_of_languages_and_days(): void
    {
        [, $profile] = $this->tutorReadyToTeach('Thandeka Mokoena');
        $learner = $this->person('List Learner');

        // Marketplace fix (D-1117): array query values used to be nested by only('languages.*') and refused.
        $this->actingAs($learner)->get(route('marketplace.find', [
            'subject_id' => $this->subject('mathematics')->id, 'languages' => ['zu', 'en'], 'weekdays' => [1, 2, 3, 4, 5, 6, 7], 'bilingual_ok' => '1',
        ]))->assertOk()->assertSessionHasNoErrors()->assertSee('Thandeka Mokoena')->assertSee('Can teach bilingually');
        $this->actingAs($learner)->get(route('marketplace.find', ['subject_id' => $this->subject('mathematics')->id, 'languages' => 'xh']))
            ->assertOk()->assertSee('No tutor fits all of that yet')->assertSee('Add another language you understand');
    }

    public function test_storefront_booking_and_the_lesson_page(): void
    {
        [$tutorUser, $profile, $offeringId] = $this->tutorReadyToTeach('Thandeka Mokoena');
        $learner = $this->person('Sipho Dlamini');
        $this->learnerProfile($learner, 19);

        $this->actingAs($learner)->get(route('marketplace.tutors.show', $profile))->assertOk()
            ->assertSee('Request this lesson')->assertSee('Not yet verified')->assertSee('Next open times')
            ->assertSee('Grade 11 Maths lesson')->assertSee('Report this profile');

        $book = $this->actingAs($learner)->get(route('learn.book', $offeringId))->assertOk()
            ->assertSee('Choose a time')->assertSee('This is the whole price')->assertSee('Free cancellation up to 24 hours');
        $this->assertMatchesRegularExpression('/name="starts_at" value="[^"]+"/', $book->getContent());

        $slot = $this->slotAt($profile, 3)->utc()->toIso8601String();
        $payload = ['subject_id' => $this->subject('mathematics')->id, 'starts_at' => $slot, 'notes' => 'Quadratics test on Friday', 'idempotency_key' => 'form-key-123456'];
        $this->actingAs($learner)->post(route('learn.book.store', $offeringId), $payload)->assertRedirect();
        $this->actingAs($learner)->post(route('learn.book.store', $offeringId), $payload)->assertRedirect();
        $this->assertSame(1, TutorSession::query()->where('student_id', $learner->id)->count(), 'a double submit books once');
        $session = TutorSession::query()->where('student_id', $learner->id)->firstOrFail();

        $this->actingAs($learner)->get(route('learn.lessons.show', $session))->assertOk()
            ->assertSee('Waiting for the tutor to accept or decline.')->assertSee('What has happened')
            ->assertSee('If you cancel now')->assertSee('nothing has been paid');
        $this->actingAs($learner)->get(route('marketplace.bookings'))->assertOk()->assertSee('Waiting for the tutor to accept or decline.');

        // Someone else cannot open it.
        $this->actingAs($this->person('Nosy Neighbour'))->get(route('learn.lessons.show', $session))->assertForbidden();

        // Accepted: pay through Paystack (faked HTTP): the learner is sent to Paystack's own checkout.
        $this->actingAs($tutorUser)->postJson("/api/tutor/bookings/{$session->id}/accept")->assertOk();
        $this->actingAs($learner)->get(route('learn.lessons.show', $session))->assertOk()->assertSee('with Paystack')->assertSee('Test mode');
        $pay = $this->actingAs($learner)->post(route('learn.lessons.pay', $session));
        $this->assertStringStartsWith('https://checkout.paystack.com/', (string) $pay->headers->get('Location'));
        $this->paystackConfirmsLatestPayment();
        $this->assertSame('confirmed', $session->fresh()->status);
        $this->actingAs($learner)->get(route('learn.lessons.show', $session))->assertOk()->assertSee('Add to my calendar')->assertSee('full refund');

        // Cancel within the free notice: the marketplace records a full refund due.
        $this->actingAs($learner)->post(route('learn.lessons.cancel', $session), ['reason' => 'Exam moved'])->assertRedirect(route('learn.lessons.show', $session));
        $this->assertSame('cancelled', $session->fresh()->status);
        $this->assertSame(100, $session->events()->where('event', 'cancelled')->first()->context['refund_due_percent']);
    }

    public function test_a_completed_lesson_can_be_reviewed_once(): void
    {
        [$tutorUser, $profile, $offeringId] = $this->tutorReadyToTeach('Thandeka Mokoena');
        $learner = $this->person('Sipho Dlamini');
        $this->learnerProfile($learner, 19);
        $id = $this->requestLesson($learner, $offeringId, $profile)->assertCreated()->json('data.id');
        $session = TutorSession::query()->findOrFail($id);
        $session->forceFill(['status' => 'completed'])->save();

        $this->actingAs($learner)->get(route('learn.lessons.show', $session))->assertOk()->assertSee('How did it go?')->assertSee('Send the review');
        $this->actingAs($learner)->post(route('learn.lessons.review', $session), ['rating' => 5, 'review' => 'Clear and patient.'])
            ->assertRedirect(route('learn.lessons.show', $session));
        $this->actingAs($learner)->get(route('learn.lessons.show', $session))->assertSee('You gave 5 out of 5')->assertDontSee('Send the review');
        $this->actingAs($learner)->get(route('marketplace.tutors.show', $profile))->assertSee('Clear and patient.');
    }

    public function test_a_limited_minor_sees_why_booking_waits_and_the_server_refuses_it(): void
    {
        [, $profile, $offeringId] = $this->tutorReadyToTeach('Thandeka Mokoena');
        $child = $this->person('Lerato Young');
        $this->learnerProfile($child, 9, 'za-caps', 'grade-4', ['zu']);

        $this->actingAs($child)->get(route('learn.book', $offeringId))->assertOk()->assertSee('You cannot book this yet')->assertSee('Invite a parent or guardian');
        $this->actingAs($child)->post(route('learn.book.store', $offeringId), [
            'subject_id' => $this->subject('mathematics')->id, 'starts_at' => $this->slotAt($profile, 3)->utc()->toIso8601String(),
        ]);
        $this->assertSame(0, TutorSession::query()->where('student_id', $child->id)->count(), 'refused server-side, not only hidden');
    }

    public function test_learning_screens_render_for_the_learner(): void
    {
        $learner = $this->person('Busi Learner');
        $this->learnerProfile($learner, 17, 'za-caps', 'grade-11', ['en']);

        $this->actingAs($learner)->get(route('learn.courses'))->assertOk()->assertSee('No course in progress')->assertSee('No certificates yet');
        $this->actingAs($learner)->get(route('learn.progress'))->assertOk()->assertSee('In a few words')->assertSee('No topics yet')
            ->assertSee('There are no rankings or leaderboards');
        $this->actingAs($learner)->get(route('money.orders'))->assertOk()->assertSee('No purchases yet');
        $this->actingAs($learner)->get(route('learn.account'))->assertOk()
            ->assertSee('Language of this website')->assertSee('Partly translated')->assertSee('Not translated yet, shown in English')
            ->assertSee('Follow my grade')->assertSee('13 to 17');
        $this->actingAs($learner)->get(route('academic.profile.edit'))->assertOk()->assertSee('Your learning profile')->assertSee('td-app--learner', false);
    }

    public function test_the_language_page_is_the_learner_page_when_signed_in_and_the_guest_page_otherwise(): void
    {
        $this->actingAs($this->person('Locale Learner'))->get(route('academic.locale.index'))->assertOk()
            ->assertSee('td-app--learner', false)->assertSee('Not translated yet, shown in English')->assertSee('isiZulu');
        auth()->logout();
        $this->get(route('academic.locale.index'))->assertOk()->assertDontSee('td-app--learner', false)->assertSee('isiZulu');
    }

    public function test_flashcards_are_created_reviewed_and_graded_through_the_learning_rules(): void
    {
        $learner = $this->person('Card Learner');
        $this->actingAs($learner)->get(route('practice.index'))->assertOk()->assertSee('Nothing set yet')->assertSee('Make a set of cards');

        $this->actingAs($learner)->post(route('practice.cards.store'), [
            'title' => 'Times tables', 'subject_id' => '', 'cards_text' => "7 x 8 | 56\n6 x 9 | 54\n",
        ])->assertRedirect();
        $set = FlashcardSet::query()->where('owner_id', $learner->id)->firstOrFail();
        $this->assertSame(2, $set->cards()->count());

        $card = $set->cards()->orderBy('position')->first();
        $this->actingAs($learner)->get(route('practice.cards.show', $set->id))->assertOk()->assertSee('7 x 8')->assertSee('2 cards to review now.');
        $this->actingAs($learner)->post(route('practice.cards.grade', [$set->id, $card->id]), ['grade' => 5])->assertRedirect(route('practice.cards.show', $set->id));
        $this->actingAs($learner)->get(route('practice.cards.show', $set->id))->assertSee('1 card to review now.');

        // Empty cards are refused by the learning validation, and another learner cannot open a private set.
        $this->actingAs($learner)->post(route('practice.cards.store'), ['title' => 'Empty', 'cards_text' => ''])->assertSessionHasErrors('cards');
        $this->actingAs($this->person('Other'))->get(route('practice.cards.show', $set->id))->assertNotFound();
    }

    public function test_the_shell_lights_the_learner_navigation_and_shows_the_bell(): void
    {
        $learner = $this->person('Nav Learner');
        $html = $this->actingAs($learner)->get(route('practice.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<a class="td-nav-link" href="[^"]+\/learn\/practice"\s+aria-current="page"\s*>/', $html);
        $this->assertStringContainsString(route('comms.inbox'), $html, 'the notifications bell is in the top bar');
        $this->assertStringContainsString(route('ai.index'), $html, 'the study assistant is in the navigation');
    }
}
