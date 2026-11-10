<?php

namespace Tests\Feature\Safety;

use App\Domain\Safety\Contracts\GuardianApprovals;
use App\Domain\Safety\Models\GuardianApproval;
use App\Domain\Safety\Notifications\GuardianApprovalDecided;
use App\Domain\Safety\Notifications\GuardianApprovalRequested;
use App\Domain\Safety\Services\DatabaseGuardianApprovals;
use App\Models\TutorSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class GuardianApprovalsTest extends TestCase
{
    use BuildsSafetyState;
    use RefreshDatabase;

    public function test_a_child_always_needs_approval_and_a_teen_follows_settings(): void
    {
        $approvals = app(GuardianApprovals::class);
        $child = $this->child();
        $teen = $this->teen();
        $guardian = $this->adult('Parent');
        $link = $this->linkGuardian($teen, $guardian);

        foreach (config('safety.approvals.kinds') as $kind) {
            $this->assertTrue($approvals->requires($child, $kind), $kind);
        }

        $this->assertTrue($approvals->requires($teen, 'booking'));
        $this->assertFalse($approvals->requires($teen, 'live_lesson'));
        $this->assertFalse($approvals->requires($this->adult(), 'booking'));

        $this->actingAs($guardian)->postJson(route('safety.guardian.links.settings', $link), ['settings' => ['booking' => false, 'live_lesson' => true]])
            ->assertOk();

        $this->assertFalse($approvals->requires($teen, 'booking'));
        $this->assertTrue($approvals->requires($teen, 'live_lesson'));
    }

    public function test_a_childs_approval_settings_cannot_be_relaxed(): void
    {
        $child = $this->child();
        $guardian = $this->adult('Parent');
        $link = $this->linkGuardian($child, $guardian);

        $this->actingAs($guardian)->postJson(route('safety.guardian.links.settings', $link), ['settings' => ['booking' => false]])
            ->assertUnprocessable();
        $this->assertTrue(app(GuardianApprovals::class)->requires($child, 'booking'));
    }

    public function test_requests_notify_guardians_and_only_a_guardian_decides(): void
    {
        $child = $this->child();
        $guardian = $this->adult('Parent');
        $this->linkGuardian($child, $guardian);
        Notification::fake();
        $subject = $this->makeTutor([], null, $this->adult('Seller'));

        $approval = app(GuardianApprovals::class)->request($child, 'purchase', $subject, ['summary' => 'Exam pack', 'amount_minor' => 9900, 'currency' => 'ZAR']);

        Notification::assertSentTo($guardian, GuardianApprovalRequested::class);
        $this->assertSame($approval->id, app(GuardianApprovals::class)->request($child, 'purchase', $subject)->id, 'an open request is reused');

        $this->actingAs($this->adult('Stranger'))->postJson(route('safety.guardian.approvals.decide', $approval), ['decision' => 'approve'])->assertForbidden();
        $this->actingAs($child)->postJson(route('safety.guardian.approvals.decide', $approval), ['decision' => 'approve'])->assertForbidden();

        $this->actingAs($guardian)->postJson(route('safety.guardian.approvals.decide', $approval), ['decision' => 'decline', 'note' => 'Not now'])
            ->assertOk()->assertJsonPath('approval.status', 'declined');
        Notification::assertSentTo($child, GuardianApprovalDecided::class);

        $this->actingAs($guardian)->postJson(route('safety.guardian.approvals.decide', $approval), ['decision' => 'approve'])->assertUnprocessable();
    }

    public function test_requests_expire(): void
    {
        $child = $this->child();
        $guardian = $this->adult('Parent');
        $this->linkGuardian($child, $guardian);
        $subject = $this->makeTutor([], null, $this->adult('Seller'));
        $approval = app(GuardianApprovals::class)->request($child, 'purchase', $subject);

        $this->travel(73)->hours();

        $this->assertSame('expired', app(GuardianApprovals::class)->status($child, 'purchase', $subject));
        $this->artisan('safety:expire-approvals')->assertSuccessful();
        $this->assertSame(GuardianApproval::EXPIRED, $approval->fresh()->status);
        $this->actingAs($guardian)->postJson(route('safety.guardian.approvals.decide', $approval), ['decision' => 'approve'])->assertUnprocessable();
    }

    public function test_a_minor_booking_through_the_existing_booking_endpoint_waits_for_the_guardian(): void
    {
        $child = $this->child();
        $guardian = $this->adult('Parent');
        $this->linkGuardian($child, $guardian);
        $tutorUser = $this->adult('Tutor');
        $this->screen($tutorUser);
        $profile = $this->makeTutor([], null, $tutorUser);
        $this->openWeek($profile);
        $subject = $profile->subjects()->first();

        $this->actingAs($child)->post(route('tutors.sessions.store', $profile), $this->bookingPayload($subject, $this->slotAt($profile, 3)));

        $session = TutorSession::query()->where('student_id', $child->id)->sole();
        $approval = GuardianApproval::query()->where('subject_id', $session->id)->sole();
        $this->assertSame('booking', $approval->kind);
        $this->assertStringContainsString('with Tutor', $approval->summary);

        // The tutor cannot confirm before the guardian approves: the server refuses whatever the UI shows.
        $this->actingAs($tutorUser)->post(route('sessions.confirm', $session));
        $this->assertSame('pending', $session->fresh()->status);

        app(DatabaseGuardianApprovals::class)->decide($approval, $guardian, true);
        $this->actingAs($tutorUser)->post(route('sessions.confirm', $session));
        $this->assertSame('confirmed', $session->fresh()->status);
    }

    public function test_a_minor_cannot_book_an_unscreened_tutor_through_the_existing_endpoint(): void
    {
        $teen = $this->teen();
        $this->linkGuardian($teen);
        $profile = $this->makeTutor([], null, $this->adult('Tutor'));
        $this->openWeek($profile);

        $this->actingAs($teen)->post(route('tutors.sessions.store', $profile), $this->bookingPayload($profile->subjects()->first(), $this->slotAt($profile, 3)))
            ->assertSessionHasErrors('safety');

        $this->assertSame(0, TutorSession::count());
    }

    public function test_a_limited_minor_cannot_book_at_all(): void
    {
        $child = $this->child();
        $tutorUser = $this->adult('Tutor');
        $this->screen($tutorUser);
        $profile = $this->makeTutor([], null, $tutorUser);
        $this->openWeek($profile);

        $this->actingAs($child)->post(route('tutors.sessions.store', $profile), $this->bookingPayload($profile->subjects()->first(), $this->slotAt($profile, 3)));

        $this->assertSame(0, TutorSession::count());
    }

    public function test_an_adult_booking_is_unchanged(): void
    {
        $adult = $this->adult('Student');
        $profile = $this->makeTutor([], null, $this->adult('Tutor'));
        $this->openWeek($profile);

        $this->actingAs($adult)->post(route('tutors.sessions.store', $profile), $this->bookingPayload($profile->subjects()->first(), $this->slotAt($profile, 3)));

        $this->assertSame(1, TutorSession::count());
        $this->assertSame(0, GuardianApproval::count());
    }
}
