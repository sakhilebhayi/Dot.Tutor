<?php

namespace Tests\Feature;

use App\Models\Subject;
use App\Models\TutorProfile;
use App\Models\TutorSession;
use App\Models\User;
use App\Notifications\TutorProfileDecision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsMarketplace;
use Tests\TestCase;

class TutorOnboardingTest extends TestCase
{
    use BuildsMarketplace;
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function application(array $subjectIds, array $overrides = []): array
    {
        return array_merge([
            'headline' => 'Matric maths that finally clicks',
            'bio' => 'Eight years teaching high-school maths and science.',
            'hourly_rate' => '350.00',
            'qualifications' => 'BSc Mathematics, PGCE',
            'timezone' => 'Africa/Johannesburg',
            'subjects' => $subjectIds,
        ], $overrides);
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/tutor/profile')->assertRedirect('/login');
    }

    public function test_a_user_without_a_profile_sees_the_application_form_with_the_configured_currency(): void
    {
        config(['tutor.currency' => 'ZAR']);
        $this->makeSubject('Algebra');
        $this->makeSubject('Retired Subject', active: false);

        $response = $this->actingAs($this->makeUser())->get('/tutor/profile');

        $response->assertOk()
            ->assertSee('Apply to teach')
            ->assertSee('name="headline"', false)
            ->assertSee('ZAR')
            ->assertSee('Algebra')
            ->assertDontSee('Retired Subject');
    }

    public function test_applying_creates_a_pending_profile_with_chosen_subjects(): void
    {
        config(['tutor.currency' => 'ZAR']);
        $algebra = $this->makeSubject('Algebra');
        $physics = $this->makeSubject('Physics');
        $user = $this->makeUser();

        $response = $this->actingAs($user)->post('/tutor/profile', $this->application([$algebra->id, $physics->id]));

        $response->assertRedirect(route('tutor.profile.edit'));
        $profile = TutorProfile::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('pending', $profile->status);
        $this->assertSame('Matric maths that finally clicks', $profile->headline);
        $this->assertSame('BSc Mathematics, PGCE', $profile->qualifications);
        $this->assertEquals(350, $profile->hourly_rate);
        $this->assertSame('ZAR', $profile->currency);
        $this->assertSame('Africa/Johannesburg', $profile->timezone);
        $this->assertEqualsCanonicalizing([$algebra->id, $physics->id], $profile->subjects()->pluck('subjects.id')->all());
    }

    public function test_a_pending_applicant_is_not_listed_in_browse(): void
    {
        $algebra = $this->makeSubject('Algebra');
        $user = $this->makeUser('Hopeful Applicant');
        $this->actingAs($user)->post('/tutor/profile', $this->application([$algebra->id]));

        $this->actingAs($this->makeUser())->get('/tutors')->assertDontSee('Hopeful Applicant');
    }

    public function test_a_user_cannot_apply_twice(): void
    {
        $algebra = $this->makeSubject('Algebra');
        $user = $this->makeUser();
        $this->actingAs($user)->post('/tutor/profile', $this->application([$algebra->id]));

        $second = $this->actingAs($user)->post('/tutor/profile', $this->application([$algebra->id], ['headline' => 'Second go']));

        $second->assertRedirect(route('tutor.profile.edit'));
        $this->assertSame(1, TutorProfile::where('user_id', $user->id)->count());
        $this->assertSame('Matric maths that finally clicks', TutorProfile::firstOrFail()->headline);
    }

    public function test_application_is_validated(): void
    {
        $algebra = $this->makeSubject('Algebra');
        $retired = $this->makeSubject('Retired', active: false);
        $user = $this->makeUser();

        $this->actingAs($user)->post('/tutor/profile', $this->application([$algebra->id], ['hourly_rate' => '0']))
            ->assertSessionHasErrors('hourly_rate');
        $this->actingAs($user)->post('/tutor/profile', $this->application([$algebra->id], ['hourly_rate' => 'abc']))
            ->assertSessionHasErrors('hourly_rate');
        $this->actingAs($user)->post('/tutor/profile', $this->application([]))
            ->assertSessionHasErrors('subjects');
        $this->actingAs($user)->post('/tutor/profile', $this->application([$retired->id]))
            ->assertSessionHasErrors('subjects.0');
        $this->actingAs($user)->post('/tutor/profile', $this->application([$algebra->id], ['timezone' => 'Mars/Olympus']))
            ->assertSessionHasErrors('timezone');
        $this->actingAs($user)->post('/tutor/profile', $this->application([$algebra->id], ['headline' => '']))
            ->assertSessionHasErrors('headline');

        $this->assertSame(0, TutorProfile::count());
    }

    public function test_a_pending_applicant_can_edit_their_application(): void
    {
        $algebra = $this->makeSubject('Algebra');
        $physics = $this->makeSubject('Physics');
        $user = $this->makeUser();
        $this->actingAs($user)->post('/tutor/profile', $this->application([$algebra->id]));

        $this->actingAs($user)->put('/tutor/profile', $this->application([$physics->id], ['headline' => 'Physics for nervous people', 'hourly_rate' => '400']))
            ->assertRedirect(route('tutor.profile.edit'));

        $profile = TutorProfile::firstOrFail();
        $this->assertSame('pending', $profile->status);
        $this->assertSame('Physics for nervous people', $profile->headline);
        $this->assertEquals(400, $profile->hourly_rate);
        $this->assertSame([$physics->id], $profile->subjects()->pluck('subjects.id')->all());
    }

    public function test_an_approved_tutor_can_edit_and_stays_approved(): void
    {
        $algebra = $this->makeSubject('Algebra');
        $profile = $this->makeTutor(['headline' => 'Old'], $algebra);

        $this->actingAs($profile->user)->put('/tutor/profile', $this->application([$algebra->id], ['headline' => 'New headline']))
            ->assertRedirect(route('tutor.profile.edit'));

        $this->assertSame('approved', $profile->fresh()->status);
        $this->assertSame('New headline', $profile->fresh()->headline);
    }

    public function test_changing_the_rate_does_not_touch_sessions_already_booked(): void
    {
        $algebra = $this->makeSubject('Algebra');
        $profile = $this->makeTutor(['hourly_rate' => 45], $algebra);
        $student = $this->makeUser();
        $this->openWeek($profile);
        $slot = $this->slotAt($profile, 2);

        $this->actingAs($student)->post("/tutors/{$profile->id}/sessions", $this->bookingPayload($algebra, $slot))->assertSessionHasNoErrors();
        $booked = TutorSession::firstOrFail();
        $this->assertEquals(45, $booked->rate);

        $this->actingAs($profile->user)->put('/tutor/profile', $this->application([$algebra->id], ['hourly_rate' => '90', 'timezone' => 'UTC']));

        $this->assertEquals(90, $profile->fresh()->hourly_rate);
        $this->assertEquals(45, $booked->fresh()->rate);

        $this->actingAs($student)->post("/tutors/{$profile->id}/sessions", $this->bookingPayload($algebra, $this->slotAt($profile, 3)))->assertSessionHasNoErrors();
        $this->assertEquals(90, TutorSession::latest('id')->firstOrFail()->rate);
    }

    public function test_a_rejected_applicant_sees_why_and_must_edit_before_re_applying(): void
    {
        $algebra = $this->makeSubject('Algebra');
        $profile = $this->makeTutor(['status' => 'rejected', 'rejected_reason' => 'Please add proof of your teaching qualification.', 'headline' => 'Maths tutor', 'bio' => 'Bio', 'qualifications' => 'BSc'], $algebra);
        $payload = $this->application([$algebra->id], ['headline' => 'Maths tutor', 'bio' => 'Bio', 'qualifications' => 'BSc', 'hourly_rate' => '45', 'timezone' => 'UTC']);

        $this->actingAs($profile->user)->get('/tutor/profile')
            ->assertOk()
            ->assertSee('Please add proof of your teaching qualification.');

        // Re-submitting identical content is refused: the point is to fix what was wrong.
        $this->actingAs($profile->user)->put('/tutor/profile', $payload)->assertSessionHasErrors('headline');
        $this->assertSame('rejected', $profile->fresh()->status);

        $payload['qualifications'] = 'BSc Mathematics, PGCE certificate attached to my SACE profile';
        $this->actingAs($profile->user)->put('/tutor/profile', $payload)->assertSessionHasNoErrors();

        $fresh = $profile->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertNull($fresh->rejected_reason);
    }

    public function test_a_suspended_tutor_sees_why_and_may_re_apply_once_edited(): void
    {
        $algebra = $this->makeSubject('Algebra');
        $profile = $this->makeTutor(['status' => 'suspended', 'suspended_reason' => 'Two no-shows in one week.', 'headline' => 'H', 'bio' => 'B', 'qualifications' => 'Q'], $algebra);
        $payload = $this->application([$algebra->id], ['headline' => 'H', 'bio' => 'B', 'qualifications' => 'Q', 'hourly_rate' => '45', 'timezone' => 'UTC']);

        $this->actingAs($profile->user)->get('/tutor/profile')->assertSee('Two no-shows in one week.');
        $this->actingAs($profile->user)->put('/tutor/profile', $payload)->assertSessionHasErrors('headline');

        $payload['bio'] = 'B, plus a note on how I now confirm every booking within a day.';
        $this->actingAs($profile->user)->put('/tutor/profile', $payload)->assertSessionHasNoErrors();

        $this->assertSame('pending', $profile->fresh()->status);
        $this->assertNull($profile->fresh()->suspended_reason);
    }

    public function test_only_the_owner_edits_their_profile_and_a_user_without_one_cannot_update(): void
    {
        $algebra = $this->makeSubject('Algebra');
        $this->actingAs($this->makeUser())->put('/tutor/profile', $this->application([$algebra->id]))->assertNotFound();
    }

    public function test_operator_decisions_notify_the_applicant(): void
    {
        $algebra = $this->makeSubject('Algebra');
        $operator = $this->makeOperator();

        $approved = $this->makeTutor(['status' => 'pending'], $algebra);
        $this->actingAs($operator)->post("/operator/tutor-profiles/{$approved->id}/approve");
        $note = $approved->user->notifications()->firstOrFail();
        $this->assertSame(TutorProfileDecision::class, $note->type);
        $this->assertSame('approved', $note->data['decision']);

        $rejected = $this->makeTutor(['status' => 'pending'], $algebra);
        $this->actingAs($operator)->post("/operator/tutor-profiles/{$rejected->id}/reject", ['reason' => 'Bio is empty.']);
        $note = $rejected->user->notifications()->firstOrFail();
        $this->assertSame('rejected', $note->data['decision']);
        $this->assertSame('Bio is empty.', $note->data['reason']);

        $this->actingAs($operator)->post("/operator/tutor-profiles/{$approved->id}/suspend", ['reason' => 'Complaints.']);
        $this->assertEqualsCanonicalizing(
            ['approved', 'suspended'],
            $approved->user->notifications()->get()->pluck('data.decision')->all(),
        );
    }

    public function test_the_applicant_sees_the_decision_on_their_dashboard_and_can_mark_it_read(): void
    {
        $algebra = $this->makeSubject('Algebra');
        $profile = $this->makeTutor(['status' => 'pending'], $algebra);
        $this->actingAs($this->makeOperator())->post("/operator/tutor-profiles/{$profile->id}/reject", ['reason' => 'Add a bio please.']);

        $this->actingAs($profile->user)->get('/dashboard')
            ->assertOk()
            ->assertSee('Add a bio please.');

        $this->actingAs($profile->user)->post('/notifications/read')->assertRedirect();
        $this->assertSame(0, $profile->user->unreadNotifications()->count());
    }

    public function test_the_marketplace_can_actually_be_started_from_nothing(): void
    {
        // Operator seeds catalogue, user applies, operator approves, tutor opens hours, student books.
        $operator = $this->makeOperator();
        $this->actingAs($operator)->post('/operator/subjects', ['name' => 'Geometry', 'level' => 'high_school'])->assertSessionHasNoErrors();
        $subject = Subject::where('name', 'Geometry')->firstOrFail();

        $tutorUser = $this->makeUser('Fresh Tutor');
        $this->actingAs($tutorUser)->post('/tutor/profile', $this->application([$subject->id], ['timezone' => 'UTC']))->assertSessionHasNoErrors();
        $profile = TutorProfile::firstOrFail();
        $this->actingAs($operator)->post("/operator/tutor-profiles/{$profile->id}/approve");
        $this->actingAs($tutorUser)->post('/tutor/availability/windows', ['weekday' => 1, 'start_time' => '08:00', 'end_time' => '12:00'])->assertSessionHasNoErrors();

        $student = $this->makeUser();
        $this->actingAs($student)->get('/tutors')->assertSee('Fresh Tutor');
        $monday = $this->slotAt($profile, 0, '09:00')->next('Monday')->setTime(9, 0);
        $this->actingAs($student)->post("/tutors/{$profile->id}/sessions", $this->bookingPayload($subject, $monday))->assertSessionHasNoErrors();

        $this->assertSame(1, TutorSession::count());
    }
}
