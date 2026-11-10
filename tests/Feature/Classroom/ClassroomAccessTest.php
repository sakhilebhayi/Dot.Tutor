<?php

namespace Tests\Feature\Classroom;

use App\Domain\Classroom\Models\Classroom;
use App\Domain\Safety\Models\GuardianApproval;
use App\Domain\Safety\Services\DatabaseGuardianApprovals;
use App\Enums\SessionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Who may enter a lesson's classroom, and when: every rule asserted against the server, not the screen. */
class ClassroomAccessTest extends TestCase
{
    use BuildsClassroom;
    use RefreshDatabase;

    public function test_the_tutor_and_the_booked_learner_can_join_and_the_tutor_joining_starts_the_lesson(): void
    {
        [$tutor, $profile] = $this->tutor();
        $learner = $this->adult('Learner Sipho');
        $session = $this->lesson($profile, $learner, minutes: 5);

        $this->joinAs($learner, $session)->assertOk()->assertJsonPath('role', 'learner');
        $this->assertSame(SessionStatus::Confirmed, $session->fresh()->statusEnum(), 'a learner joining does not start the lesson');

        $this->joinAs($tutor, $session, 'tab-t')->assertOk()->assertJsonPath('role', 'tutor')->assertJsonPath('relay', false);
        $this->assertSame(SessionStatus::InProgress, $session->fresh()->statusEnum(), 'the marketplace flow started the lesson');
    }

    public function test_someone_not_booked_into_the_lesson_is_refused(): void
    {
        [, $profile] = $this->tutor();
        $session = $this->lesson($profile, $this->adult('Learner'));
        $stranger = $this->adult('Stranger');

        $this->joinAs($stranger, $session)->assertForbidden()->assertJsonPath('error', 'not_participant');
        $this->actingAs($stranger)->get(route('classroom.show', $session))->assertForbidden();
        $this->actingAs($stranger)->get(route('classroom.after', $session))->assertForbidden();
    }

    public function test_the_room_is_closed_too_early_and_too_late(): void
    {
        [$tutor, $profile] = $this->tutor();
        $learner = $this->adult('Learner');

        $early = $this->lesson($profile, $learner, minutes: 30);
        $this->joinAs($learner, $early)->assertStatus(409)->assertJsonPath('error', 'too_early')
            ->assertJsonPath('admission.opens_at', $early->starts_at->copy()->subMinutes(10)->toIso8601String());

        $this->travel(21)->minutes(); // now 9 minutes before the start: open
        $this->joinAs($learner, $early)->assertOk();

        $late = $this->lesson($profile, $this->adult('Other'), minutes: -100); // ended 40 minutes ago, grace is 30
        $this->joinAs($tutor, $late)->assertStatus(409)->assertJsonPath('error', 'closed');
    }

    public function test_a_lesson_that_is_not_confirmed_or_is_over_has_no_open_classroom(): void
    {
        [$tutor, $profile] = $this->tutor();
        $learner = $this->adult('Learner');

        $requested = $this->lesson($profile, $learner, overrides: ['status' => 'pending']);
        $this->joinAs($tutor, $requested)->assertStatus(409)->assertJsonPath('error', 'not_confirmed');

        $awaitingPayment = $this->lesson($profile, $this->adult('B'), overrides: ['status' => 'awaiting_payment']);
        $this->joinAs($tutor, $awaitingPayment)->assertStatus(409)->assertJsonPath('error', 'not_confirmed');

        $cancelled = $this->lesson($profile, $this->adult('C'), overrides: ['status' => 'cancelled']);
        $this->joinAs($tutor, $cancelled)->assertStatus(409)->assertJsonPath('error', 'lesson_over');
    }

    public function test_a_live_lesson_with_a_child_waits_for_the_guardians_approval(): void
    {
        [$tutor, $profile] = $this->tutor();
        $child = $this->child('Lerato');
        $guardian = $this->adult('Mother');
        $this->linkGuardian($child, $guardian);
        $session = $this->lesson($profile, $child);

        $this->joinAs($tutor, $session)->assertForbidden()->assertJsonPath('error', 'guardian_approval_missing');
        $this->joinAs($child, $session, 'tab-c')->assertForbidden()->assertJsonPath('error', 'guardian_approval_missing');

        // The pre-join page says why and offers the request; the child asks.
        $this->actingAs($child)->get(route('classroom.show', $session))->assertOk()->assertSee('A parent or guardian must approve this live lesson first.');
        $this->actingAs($child)->post(route('classroom.approval', $session))->assertRedirect();
        $approval = GuardianApproval::query()->where('learner_id', $child->id)->where('kind', 'live_lesson')->sole();
        $this->assertSame('pending', $approval->status);

        app(DatabaseGuardianApprovals::class)->decide($approval, $guardian, true);

        $this->joinAs($child, $session, 'tab-c')->assertOk();
        $this->joinAs($tutor, $session)->assertOk();
        // The guardian may come in to watch.
        $this->joinAs($guardian, $session, 'tab-g')->assertOk()->assertJsonPath('role', 'guardian');
    }

    public function test_a_minor_without_guardian_consent_cannot_meet_live(): void
    {
        [$tutor, $profile] = $this->tutor();
        $teen = $this->teen('Ayanda'); // no guardian linked: the account is limited
        $session = $this->lesson($profile, $teen);

        $this->joinAs($teen, $session)->assertForbidden()->assertJsonPath('error', 'guardian_consent_required');
    }

    public function test_an_unscreened_tutor_cannot_meet_a_minor(): void
    {
        [$tutor, $profile] = $this->tutor(screened: false);
        $teen = $this->teen('Ayanda');
        $this->linkGuardian($teen);
        $session = $this->lesson($profile, $teen);

        $this->joinAs($tutor, $session)->assertForbidden()->assertJsonPath('error', 'tutor_screening_missing');
        $this->joinAs($teen, $session, 'tab-l')->assertForbidden()->assertJsonPath('error', 'tutor_screening_missing');
    }

    public function test_group_lessons_admit_seat_holders_and_the_guardian_approves_the_group_classroom(): void
    {
        [$tutor, $profile] = $this->tutor();
        // An unscreened adult may not share a live room with a child (safety's rule), so this group is two minors.
        $adult = $this->teen('Teen learner');
        $this->linkGuardian($adult);
        $child = $this->child('Child learner');
        $guardian = $this->adult('Guardian');
        $this->linkGuardian($child, $guardian);
        $session = $this->groupLesson($profile, [$adult, $child]);

        $this->joinAs($this->adult('No seat'), $session)->assertForbidden()->assertJsonPath('error', 'not_participant');
        $this->joinAs($adult, $session)->assertForbidden()->assertJsonPath('error', 'guardian_approval_missing');

        $this->actingAs($tutor)->post(route('classroom.approval', $session))->assertRedirect();
        $approval = GuardianApproval::query()->where('learner_id', $child->id)->where('kind', 'live_lesson')->sole();
        $this->assertSame((new Classroom)->getMorphClass(), $approval->subject_type, 'a group lesson is approved as its classroom');
        app(DatabaseGuardianApprovals::class)->decide($approval, $guardian, true);

        $this->joinAs($adult, $session)->assertOk();
        $this->joinAs($child, $session, 'tab-c')->assertOk();
    }

    public function test_the_mesh_has_a_cap(): void
    {
        config(['classroom.max_in_room' => 2]);
        [$tutor, $profile] = $this->tutor();
        $a = $this->adult('A');
        $b = $this->adult('B');
        $session = $this->groupLesson($profile, [$a, $b]);

        $this->joinAs($tutor, $session, 't')->assertOk();
        $this->joinAs($a, $session, 'a')->assertOk();
        $this->joinAs($b, $session, 'b')->assertStatus(409)->assertJsonPath('error', 'room_full');
    }

    public function test_a_removed_learner_cannot_come_back(): void
    {
        [$tutor, $profile] = $this->tutor();
        $learner = $this->adult('Learner');
        $session = $this->lesson($profile, $learner);
        $tutorRow = $this->joinAs($tutor, $session, 't')->json('attendee_id');
        $learnerRow = $this->joinAs($learner, $session, 'l')->json('attendee_id');
        $this->assertNotNull($tutorRow);

        $this->actingAs($learner)->withHeader('X-Client-Id', 'l')->postJson(route('classroom.api.tutor', $session), ['action' => 'remove', 'attendee_id' => $tutorRow])
            ->assertForbidden()->assertJsonPath('error', 'tutor_only');
        $this->actingAs($tutor)->withHeader('X-Client-Id', 't')->postJson(route('classroom.api.tutor', $session), ['action' => 'remove', 'attendee_id' => $learnerRow])->assertOk();

        $this->pollAs($learner, $session, 'l')->assertForbidden()->assertJsonPath('error', 'removed');
        $this->joinAs($learner, $session, 'l2')->assertForbidden()->assertJsonPath('error', 'removed');
    }

    public function test_the_tutor_ends_the_lesson_and_only_the_tutor_may_reopen_it(): void
    {
        [$tutor, $profile] = $this->tutor();
        $learner = $this->adult('Learner');
        $session = $this->lesson($profile, $learner);
        $this->joinAs($tutor, $session, 't');
        $this->joinAs($learner, $session, 'l');

        $this->actingAs($learner)->postJson(route('classroom.api.end', $session))->assertForbidden();
        $this->actingAs($tutor)->postJson(route('classroom.api.end', $session))->assertOk()->assertJsonPath('ended', true);

        $this->pollAs($learner, $session, 'l')->assertStatus(409)->assertJsonPath('error', 'ended');
        $this->joinAs($learner, $session, 'l')->assertStatus(409)->assertJsonPath('error', 'ended');
        $this->joinAs($tutor, $session, 't2')->assertOk();
    }

    public function test_the_pre_join_page_is_honest_about_recording_and_relays(): void
    {
        [$tutor, $profile] = $this->tutor();
        $session = $this->lesson($profile, $this->adult('Learner'));

        $this->actingAs($tutor)->get(route('classroom.show', $session))->assertOk()
            ->assertSee('This lesson is not recorded.')
            ->assertSee('No relay server is set up')
            ->assertHeader('Permissions-Policy', 'camera=(self), microphone=(self), display-capture=(self), geolocation=(), payment=(), usb=()');
        // Everywhere else the app-wide policy still denies camera and microphone.
        $this->actingAs($tutor)->get(route('classroom.after', $session))->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
    }
}
