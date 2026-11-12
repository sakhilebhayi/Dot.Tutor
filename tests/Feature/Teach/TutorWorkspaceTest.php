<?php

namespace Tests\Feature\Teach;

use App\Domain\Learning\Enums\CourseStatus;
use App\Domain\Learning\Models\Course;
use App\Domain\Money\Models\Earning;
use App\Domain\Money\Models\Payout;
use App\Domain\Money\Models\PayoutBatch;
use App\Domain\Safety\Models\AuditEntry;
use App\Domain\Teach\Models\Onboarding;
use App\Domain\Teach\Models\ReferralLink;
use App\Domain\Teach\Models\ReferralVisit;
use App\Domain\Teach\Models\StudentNote;
use App\Models\TutorProfile;
use App\Models\TutorSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Acceptance\BuildsJourneys;
use Tests\TestCase;

/**
 * The tutor workspace (tutor UI agent, R-47): every screen renders for a tutor, actions go through the owning
 * domains, and a tutor never sees or touches another tutor's bookings, students, offerings, courses or money.
 */
class TutorWorkspaceTest extends TestCase
{
    use BuildsJourneys;
    use RefreshDatabase;

    public function test_guests_are_sent_to_sign_in_and_people_without_a_profile_to_onboarding(): void
    {
        $this->get('/teach')->assertRedirect('/login');
        $this->get('/r/abcdefgh12')->assertRedirect('/login');

        $user = $this->makeUser('New Person');
        $this->actingAs($user)->get('/teach')->assertRedirect(route('teach.profile'));
        $this->actingAs($user)->get('/teach/bookings')->assertRedirect(route('teach.profile'));
        $this->actingAs($user)->get(route('teach.profile'))->assertOk()->assertSee('Become a tutor')->assertSee('Save draft and continue');
    }

    public function test_onboarding_starts_a_draft_that_no_operator_sees_and_sends_only_when_complete(): void
    {
        $this->setUpWorld();
        $user = $this->person('Thandi Mokoena');

        $this->actingAs($user)->post(route('teach.profile.start'), [
            'headline' => 'Grade 10 to 12 Maths, CAPS',
            'introduction' => 'Second-year engineering student who tutors high school Maths after lectures.',
            'teaches_online' => '1', 'teaches_in_person' => '0', 'timezone' => 'Africa/Johannesburg',
        ])->assertRedirect(route('teach.profile', ['step' => 'photo']));

        $profile = TutorProfile::query()->where('user_id', $user->id)->sole();
        $this->assertSame('draft', $profile->status);
        $this->assertNull($profile->hourly_rate_minor, 'offerings own prices: no profile-wide rate (D-2204)');
        $this->actingAs($this->operator)->get(route('operator.tutor-profiles.index'))->assertOk()->assertDontSee('Thandi Mokoena');

        // Sending before the required steps is refused, with the missing steps named.
        $this->actingAs($user)->post(route('teach.profile.submit'))->assertSessionHasErrors('submit');
        $this->assertSame('draft', $profile->fresh()->status);

        $maths = $this->subject('mathematics');
        $this->actingAs($user)->put(route('teach.profile.scope'), ['scopes' => [
            ['subject_id' => $maths->id, 'level_min_id' => $this->level('za-caps', 'grade-10')->id, 'level_max_id' => $this->level('za-caps', 'grade-12')->id],
            ['subject_id' => '', 'level_min_id' => '', 'level_max_id' => ''],
        ]])->assertSessionHasNoErrors();
        $scope = $profile->teachingScopes()->sole();
        $this->assertSame($this->level('za-caps', 'grade-10')->curriculum_id, (int) $scope->curriculum_id, 'The curriculum comes from the level');

        $this->actingAs($user)->put(route('teach.profile.languages'), ['languages' => [
            ['code' => 'zu', 'proficiency' => 'native'], ['code' => '', 'proficiency' => 'fluent'],
        ]])->assertSessionHasNoErrors();
        $this->actingAs($user)->put(route('teach.profile.safeguarding'), ['teaches_minors' => '1'])->assertSessionHasNoErrors();
        $this->actingAs($user)->post(route('teach.calendar.hours.store'), ['weekday' => 2, 'start_time' => '15:00', 'end_time' => '19:00'])->assertSessionHasNoErrors();
        $this->actingAs($user)->post(route('teach.offerings.store'), [
            'type' => 'one_to_one', 'title' => 'Maths lesson', 'price' => '180', 'duration_minutes' => 60, 'format' => 'online',
            'subject_ids' => [$maths->id], 'cancellation_notice_hours' => 24, 'late_cancellation_refund_percent' => 50,
        ])->assertSessionHasNoErrors();

        // The safeguarding step shows the screening state without pretending anything was checked.
        $this->actingAs($user)->get(route('teach.profile', ['step' => 'safeguarding']))->assertOk()->assertSee('Not recorded');

        $this->actingAs($user)->post(route('teach.profile.submit'))->assertSessionHasNoErrors();
        $this->assertSame('pending', $profile->fresh()->status);
        $this->assertNotNull(Onboarding::query()->where('tutor_profile_id', $profile->id)->value('submitted_at'));
        $this->assertTrue(AuditEntry::query()->where('action', 'tutor.profile.submitted')->exists());
        $this->actingAs($user)->get(route('teach.profile'))->assertOk()->assertSee('Sent, under review');

        // After a rejection the reason is shown, and sending again needs a note on what changed.
        $this->actingAs($this->operator)->post(route('operator.tutor-profiles.reject', $profile), ['reason' => 'Add a teaching approach.']);
        $this->actingAs($user)->get(route('teach.profile', ['step' => 'send']))->assertOk()->assertSee('Add a teaching approach.')->assertSee('Not approved');
        $this->actingAs($user)->post(route('teach.profile.submit'))->assertSessionHasErrors('resubmission_note');
        $this->actingAs($user)->post(route('teach.profile.submit'), ['resubmission_note' => 'Added how my lessons run.'])->assertSessionHasNoErrors();
        $this->assertSame('pending', $profile->fresh()->status);
    }

    public function test_every_workspace_screen_renders_for_an_approved_tutor_with_real_data(): void
    {
        $this->setUpWorld();
        [$tutor, $profile, $offeringId] = $this->tutorReadyToTeach('Lerato Khumalo', 30000);
        $learner = $this->person('Anele Ngcobo');
        $this->learnerProfile($learner, 20);
        $this->requestLesson($learner, $offeringId, $profile)->assertCreated();
        $course = Course::create(['owner_id' => $tutor->id, 'slug' => 'c-'.Str::random(5), 'title' => 'Functions in four weeks', 'original_locale' => 'en', 'currency' => 'ZAR', 'status' => CourseStatus::Draft]);

        $pages = [
            route('teach.home') => 'Requests to answer',
            route('teach.bookings') => 'Anele Ngcobo',
            route('teach.bookings', ['view' => 'upcoming']) => 'No upcoming lessons',
            route('teach.bookings', ['view' => 'waiting']) => 'Waiting',
            route('teach.bookings', ['view' => 'groups']) => 'Create a group offering',
            route('teach.bookings', ['view' => 'packages']) => 'No packages sold yet',
            route('teach.bookings', ['view' => 'past']) => 'No past lessons yet',
            route('teach.bookings', ['view' => 'cancelled']) => 'Nothing cancelled',
            route('teach.calendar') => 'Weekly open hours',
            route('teach.students') => 'Your students',
            route('teach.offerings') => 'Grade 11 Maths lesson',
            route('teach.offerings.create') => 'What kind of offering?',
            route('teach.offerings.create', ['type' => 'package']) => 'Price for the whole package',
            route('teach.offerings.edit', $offeringId) => 'You keep',
            route('teach.storefront') => 'Referral link',
            route('teach.storefront.promo') => 'Book on Dot.Tutor',
            route('teach.courses') => 'Functions in four weeks',
            route('teach.courses.create') => 'Create draft',
            route('teach.materials') => 'Upload to the library',
            route('teach.materials.assessments') => 'Question banks',
            route('teach.earnings') => 'Income report (CSV)',
            route('teach.payouts') => 'Bank details',
            route('teach.insights') => 'Lesson requests per week',
            route('teach.profile') => 'Profile and verification',
        ];
        foreach (['basics', 'outcomes', 'structure', 'quizzes', 'price', 'publish', 'learners'] as $tab) {
            $pages[route('teach.courses.show', [$course, 'tab' => $tab])] = 'Functions in four weeks';
        }

        foreach ($pages as $url => $text) {
            $this->actingAs($tutor)->get($url)->assertOk()->assertSee($text, false);
        }

        // The learner appears as a student, with a page of their own.
        $this->actingAs($tutor)->get(route('teach.students.show', $learner->id))->assertOk()->assertSee('Your private note');
    }

    public function test_requests_are_answered_through_the_booking_flow_with_reasons(): void
    {
        $this->setUpWorld();
        [$tutor, $profile, $offeringId] = $this->tutorReadyToTeach('Lerato Khumalo', 30000);
        $learner = $this->person('Anele Ngcobo');
        $this->learnerProfile($learner, 20);
        $first = $this->requestLesson($learner, $offeringId, $profile, 3)->json('data.id');
        $second = $this->requestLesson($learner, $offeringId, $profile, 4)->json('data.id');

        $this->actingAs($tutor)->from(route('teach.bookings'))->post(route('teach.bookings.act', [$first, 'accept']))->assertRedirect(route('teach.bookings'));
        $this->assertSame('awaiting_payment', TutorSession::query()->findOrFail($first)->status);

        $this->actingAs($tutor)->post(route('teach.bookings.act', [$second, 'decline']), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($tutor)->post(route('teach.bookings.act', [$second, 'decline']), ['reason' => 'I am away that week.'])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', TutorSession::query()->findOrFail($second)->status);

        // A wrong move is refused by the state machine and shown as a message, not a crash.
        $this->actingAs($tutor)->post(route('teach.bookings.act', [$second, 'accept']))->assertSessionHas('error');
    }

    public function test_offerings_take_prices_in_rands_and_the_preview_uses_the_commission_rule(): void
    {
        $this->setUpWorld();
        [$tutor] = $this->tutorReadyToTeach('Lerato Khumalo', 30000);
        $maths = $this->subject('mathematics');

        $this->actingAs($tutor)->post(route('teach.offerings.store'), [
            'type' => 'package', 'title' => 'Five lessons', 'price' => '1250,50', 'duration_minutes' => 60, 'format' => 'online',
            'subject_ids' => [$maths->id], 'package_lessons' => 5, 'package_valid_days' => 90,
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('tutor_offerings', ['title' => 'Five lessons', 'price_minor' => 125050, 'package_lessons' => 5]);

        $this->actingAs($tutor)->postJson(route('teach.offerings.preview'), ['price_minor' => 20000])->assertOk()
            ->assertJsonPath('commission_minor', 3000)->assertJsonPath('net_minor', 17000);
        $this->actingAs($tutor)->get(route('teach.offerings'))->assertOk()->assertSee('Five lessons');

        $this->actingAs($tutor)->post(route('teach.offerings.coupons.store'), ['code' => 'WINTER10', 'kind' => 'percent', 'percent' => '10', 'per_user_limit' => 1, 'max_redemptions' => 20])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('money_coupons', ['code' => 'WINTER10', 'owner_id' => $tutor->id, 'funded_by' => 'tutor', 'max_redemptions' => 20]);
    }

    public function test_a_tutor_never_sees_or_touches_another_tutors_business(): void
    {
        $this->setUpWorld();
        [$alice, $aliceProfile, $aliceOffering] = $this->tutorReadyToTeach('Alice Dlamini', 30000);
        [$bob, $bobProfile, $bobOffering] = $this->tutorReadyToTeach('Bob Naidoo', 20000);
        $learner = $this->person('Sipho Learner');
        $this->learnerProfile($learner, 20);
        $bobBooking = $this->requestLesson($learner, $bobOffering, $bobProfile)->json('data.id');
        $bobCourse = Course::create(['owner_id' => $bob->id, 'slug' => 'b-'.Str::random(5), 'title' => 'Bob course', 'original_locale' => 'en', 'currency' => 'ZAR', 'status' => CourseStatus::Draft]);
        StudentNote::query()->create(['tutor_profile_id' => $bobProfile->id, 'learner_id' => $learner->id, 'body' => 'Bob private note']);
        $batch = PayoutBatch::query()->forceCreate(['status' => 'draft', 'period_end' => now(), 'currency' => 'ZAR']);
        $payout = Payout::query()->forceCreate(['batch_id' => $batch->id, 'payee_id' => $bob->id, 'reference' => 'PO-TEST-1', 'amount_minor' => 10000, 'currency' => 'ZAR', 'status' => 'draft', 'method' => 'manual']);

        // Alice sees none of it.
        $this->actingAs($alice)->get(route('teach.bookings'))->assertOk()->assertDontSee('Sipho Learner');
        $this->actingAs($alice)->get(route('teach.students'))->assertOk()->assertDontSee('Sipho Learner');
        $this->actingAs($alice)->get(route('teach.students.show', $learner->id))->assertNotFound();
        $this->actingAs($alice)->put(route('teach.students.note', $learner->id), ['body' => 'x'])->assertNotFound();
        $this->actingAs($alice)->post(route('teach.bookings.act', [$bobBooking, 'accept']))->assertNotFound();
        $this->actingAs($alice)->get(route('teach.offerings.edit', $bobOffering))->assertNotFound();
        $this->actingAs($alice)->put(route('teach.offerings.update', $bobOffering), ['title' => 'Hijacked'])->assertNotFound();
        $this->actingAs($alice)->post(route('teach.offerings.archive', $bobOffering))->assertNotFound();
        $this->actingAs($alice)->get(route('teach.courses.show', $bobCourse))->assertForbidden();
        $this->actingAs($alice)->put(route('teach.courses.details', $bobCourse), ['title' => 'Hijacked'])->assertForbidden();
        $this->actingAs($alice)->get(route('teach.payouts.show', $payout))->assertNotFound();
        $this->actingAs($alice)->get(route('teach.courses'))->assertOk()->assertDontSee('Bob course');

        $this->assertSame('pending', TutorSession::query()->findOrFail($bobBooking)->status);
        $this->assertDatabaseMissing('tutor_offerings', ['title' => 'Hijacked']);
        $this->assertSame('Bob private note', StudentNote::query()->sole()->body);

        // Bob sees his own learner and note, and only he can write it.
        $this->actingAs($bob)->get(route('teach.students.show', $learner->id))->assertOk()->assertSee('Bob private note');
    }

    public function test_earnings_payouts_and_the_income_report_show_the_tutors_real_ledger(): void
    {
        $this->setUpWorld();
        [$tutor, $profile, $offeringId] = $this->tutorReadyToTeach('Lerato Khumalo', 25500);
        [$other, $otherProfile, $otherOffering] = $this->tutorReadyToTeach('Other Tutor', 40000);
        $learner = $this->person('Anele Ngcobo');
        $this->learnerProfile($learner, 20);

        $bookingId = $this->requestLesson($learner, $offeringId, $profile)->json('data.id');
        $this->actingAs($tutor)->post(route('teach.bookings.act', [$bookingId, 'accept']))->assertSessionHasNoErrors();
        $this->actingAs($learner)->postJson("/api/tutor/bookings/{$bookingId}/pay")->assertOk();
        $this->paystackConfirmsLatestPayment();

        $earning = Earning::query()->where('seller_id', $tutor->id)->sole();
        $this->actingAs($tutor)->get(route('teach.earnings'))->assertOk()
            // The row names the learner and what was sold, never the tutor herself (D-2207).
            ->assertSee('Anele Ngcobo')->assertDontSee('lesson with Lerato Khumalo')->assertSee('Pending');
        $this->actingAs($tutor)->get(route('teach.home'))->assertOk()->assertSee('Next payout run');

        $csv = $this->actingAs($tutor)->get(route('teach.earnings.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString('Anele Ngcobo', $csv);
        $this->assertStringNotContainsString('lesson with Lerato Khumalo', $csv);
        $this->assertStringContainsString(number_format($earning->net_minor / 100, 2, '.', ''), $csv);
        $this->assertStringContainsString('Totals', $csv);
        $otherCsv = $this->actingAs($other)->get(route('teach.earnings.export'))->assertOk()->streamedContent();
        $this->assertStringNotContainsString('Lerato Khumalo', $otherCsv, 'Another tutor\'s report holds only their own earnings');

        // Bank details: only the last four digits come back.
        $this->actingAs($tutor)->withSession(['tutor.signed_in_at' => time()])->put(route('teach.payouts.account'), [
            'holder_name' => 'L Khumalo', 'account_number' => '6200 1234 567', 'bank_name' => 'First National Bank', 'bank_code' => '250655', 'account_type' => 'personal',
        ])->assertSessionHasNoErrors();
        $this->actingAs($tutor)->get(route('teach.payouts'))->assertOk()->assertSee('4567')->assertDontSee('62001234567');

        $this->actingAs($tutor)->get(route('teach.insights'))->assertOk()->assertSee('Lesson requests per week');
        $this->assertLedgerHealthy();
    }

    public function test_the_referral_link_counts_signed_in_visits_only_as_attribution(): void
    {
        $this->setUpWorld();
        [$tutor, $profile] = $this->tutorReadyToTeach('Lerato Khumalo', 30000);
        $link = ReferralLink::for($profile);
        $visitor = $this->person('Curious Learner');

        $this->actingAs($visitor)->get(route('teach.referral', $link->code))->assertRedirect(route('marketplace.tutors.show', $profile));
        $this->actingAs($visitor)->get(route('teach.referral', $link->code));
        $this->actingAs($tutor)->get(route('teach.referral', $link->code));
        $this->assertSame(1, ReferralVisit::query()->count(), 'One visit per visitor per day; the tutor\'s own visits are not counted');

        $this->actingAs($tutor)->put(route('teach.storefront.update'), [
            'headline' => 'CAPS Mathematics', 'public_handle' => 'lerato-maths', 'introduction' => 'Patient, exam-focused lessons for Grades 10 to 12 learners.',
        ])->assertSessionHasNoErrors();
        $this->get('/u/lerato-maths')->assertRedirect(route('marketplace.tutors.show', $profile));
        $this->actingAs($tutor)->get(route('teach.storefront'))->assertOk()->assertSee('/u/lerato-maths')->assertSee('no credit or discount', false);
    }

    public function test_a_minors_request_waiting_for_a_guardian_shows_only_as_a_number(): void
    {
        $this->setUpWorld();
        [$tutor, $profile] = $this->tutorReadyToTeach('Lerato Khumalo', 30000);
        $child = $this->person('Young Learner Name');
        $this->makeSession($profile, $child, $this->subject('mathematics'), ['status' => 'awaiting_guardian', 'guardian_status' => 'pending']);

        $this->actingAs($tutor)->get(route('teach.home'))->assertOk()->assertSee('waiting for their guardian')->assertDontSee('Young Learner Name');
        $this->actingAs($tutor)->get(route('teach.bookings', ['view' => 'waiting']))->assertOk()->assertSee('for a guardian')->assertDontSee('Young Learner Name');
        $this->actingAs($tutor)->get(route('teach.calendar', ['week' => now()->addDays(2)->format('Y-m-d')]))->assertOk()->assertDontSee('Young Learner Name');
        $this->actingAs($tutor)->get(route('teach.students'))->assertOk()->assertDontSee('Young Learner Name');
        $this->actingAs($tutor)->get(route('teach.students.show', $child->id))->assertNotFound();
    }
}
