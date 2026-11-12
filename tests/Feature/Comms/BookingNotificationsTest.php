<?php

namespace Tests\Feature\Comms;

use App\Domain\Comms\Contracts\MinorRouting;
use App\Domain\Comms\Inbox;
use App\Domain\Comms\Listeners\BookingEventsObserver;
use App\Domain\Comms\Models\NotificationDelivery;
use App\Domain\Comms\Support\SafetyMinorRouting;
use App\Domain\Integration\Adapters\Marketplace\SafetyGuardianApprovals;
use App\Domain\Marketplace\Contracts\GuardianApprovalsPort;
use App\Domain\Marketplace\Models\TutorSessionEvent;
use App\Domain\Safety\Models\GuardianLink;
use App\Domain\Safety\Services\Guardians;
use App\Models\TutorSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Brief section 19: booking confirmations, changes and cancellations, driven through the real booking API. */
class BookingNotificationsTest extends TestCase
{
    use BuildsComms;
    use RefreshDatabase;

    private function book(User $learner, $offering, $subject, $start)
    {
        return $this->actingAs($learner)->postJson("/api/tutor/offerings/{$offering->id}/bookings", [
            'subject_id' => $subject->id,
            'starts_at' => $start->format('Y-m-d\TH:i:s\Z'),
        ])->assertCreated();
    }

    public function test_a_request_tells_the_tutor_and_not_the_learner_who_made_it(): void
    {
        [$tutor, $subject, $offering] = $this->bookableTutor();
        $learner = $this->makeUser('Lerato Mokoena');

        $this->book($learner, $offering, $subject, $this->slotAt($tutor, 2));

        $notice = $this->inbox($tutor->user, 'lesson_requested')->sole();
        $this->assertSame('New lesson request', $notice->data['title']);
        $this->assertStringContainsString('Lerato Mokoena asked for a Algebra lesson', $notice->data['message']);
        $this->assertSame('/my/bookings', $notice->data['url']);
        $this->assertCount(0, $this->inbox($learner));
    }

    public function test_a_doubled_booking_event_reaches_each_person_once(): void
    {
        [$tutor, $subject, $offering] = $this->bookableTutor();
        $learner = $this->makeUser('Learner');
        $id = $this->book($learner, $offering, $subject, $this->slotAt($tutor, 2))->json('data.id');

        $event = TutorSessionEvent::where('tutor_session_id', $id)->sole();
        app(BookingEventsObserver::class)->created($event);
        app(BookingEventsObserver::class)->created($event);

        $this->assertCount(1, $this->inbox($tutor->user, 'lesson_requested'));
    }

    public function test_acceptance_asks_for_payment_and_payment_confirms_for_both_with_a_calendar_file(): void
    {
        $this->fakePayingCheckout();
        [$tutor, $subject, $offering] = $this->bookableTutor();
        $learner = $this->makeUser('Learner');
        $id = $this->book($learner, $offering, $subject, $this->slotAt($tutor, 2))->json('data.id');

        $this->actingAs($tutor->user)->postJson("/api/tutor/bookings/{$id}/accept")->assertOk();
        $accepted = $this->inbox($learner, 'lesson_accepted')->sole();
        $this->assertStringStartsWith('Pay by ', $accepted->data['message']);

        $this->actingAs($learner)->postJson("/api/tutor/bookings/{$id}/pay")->assertOk();

        $learnerConfirm = $this->inbox($learner, 'lesson_confirmed')->sole();
        $tutorConfirm = $this->inbox($tutor->user, 'lesson_confirmed')->sole();
        $this->assertSame($id, $learnerConfirm->data['ics_session_id']);
        $this->assertSame('Lesson confirmed and paid', $tutorConfirm->data['title']);
        // One subject: three notices about one booking count once.
        $this->assertSame(1, Inbox::unreadCount($learner));
    }

    public function test_a_free_lesson_is_confirmed_on_acceptance_without_telling_the_tutor_what_they_just_did(): void
    {
        [$tutor, $subject, $offering] = $this->bookableTutor(['price_minor' => 0]);
        $learner = $this->makeUser('Learner');
        $id = $this->book($learner, $offering, $subject, $this->slotAt($tutor, 2))->json('data.id');

        $this->actingAs($tutor->user)->postJson("/api/tutor/bookings/{$id}/accept")->assertOk();

        $this->assertCount(1, $this->inbox($learner, 'lesson_confirmed'));
        $this->assertCount(0, $this->inbox($tutor->user, 'lesson_confirmed'));
    }

    public function test_a_decline_carries_the_tutors_reason_to_the_learner(): void
    {
        [$tutor, $subject, $offering] = $this->bookableTutor();
        $learner = $this->makeUser('Learner');
        $id = $this->book($learner, $offering, $subject, $this->slotAt($tutor, 2))->json('data.id');

        $this->actingAs($tutor->user)->postJson("/api/tutor/bookings/{$id}/decline", ['reason' => 'I do not teach that syllabus.'])->assertOk();

        $notice = $this->inbox($learner, 'lesson_declined')->sole();
        $this->assertStringContainsString('I do not teach that syllabus.', $notice->data['message']);
        $this->assertSame('/find-a-tutor', $notice->data['url']);
    }

    public function test_a_cancellation_tells_the_other_party_only(): void
    {
        [$tutor, $subject, $offering] = $this->bookableTutor(['price_minor' => 0]);
        $learner = $this->makeUser('Learner');
        $id = $this->book($learner, $offering, $subject, $this->slotAt($tutor, 3))->json('data.id');
        $this->actingAs($tutor->user)->postJson("/api/tutor/bookings/{$id}/accept")->assertOk();

        $this->actingAs($learner)->postJson("/api/tutor/bookings/{$id}/cancel", ['reason' => 'Exam moved.'])->assertOk();

        $this->assertCount(1, $this->inbox($tutor->user, 'lesson_cancelled'));
        $this->assertCount(0, $this->inbox($learner, 'lesson_cancelled'));
    }

    public function test_completion_invites_the_learner_to_review(): void
    {
        [$tutor, $subject, $offering] = $this->bookableTutor(['price_minor' => 0]);
        $learner = $this->makeUser('Learner');
        $id = $this->book($learner, $offering, $subject, $this->slotAt($tutor, 1))->json('data.id');
        $this->actingAs($tutor->user)->postJson("/api/tutor/bookings/{$id}/accept")->assertOk();

        $this->travelTo(TutorSession::find($id)->starts_at->copy()->addMinutes(70));
        $this->actingAs($tutor->user)->postJson("/api/tutor/bookings/{$id}/complete")->assertOk();

        $invite = $this->inbox($learner, 'lesson_review_invitation')->sole();
        $this->assertSame('Leave a review', $invite->data['action_label']);
    }

    public function test_a_minor_booking_reaches_the_guardian_first_and_the_tutor_sees_only_a_first_name_after_approval(): void
    {
        [$tutor, $subject, $offering] = $this->bookableTutor(['price_minor' => 0]);
        $learner = $this->teen('Sipho Dlamini');
        $guardian = $this->guardianFor($learner, $this->adult('Nomsa Dlamini'));
        $this->screen($tutor->user);

        $id = $this->book($learner, $offering, $subject, $this->slotAt($tutor, 2))->json('data.id');
        $this->assertSame('awaiting_guardian', TutorSession::find($id)->status);

        // The guardian is asked once (safety's own notice under comms' rules, its own email recorded, not duplicated).
        $ask = $this->inbox($guardian, 'guardian_approval_requested')->sole();
        $this->assertSame('Approval needed', $ask->data['title']);
        $this->assertSame('guardian', $ask->data['category']);
        $this->assertSame(1, NotificationDelivery::where('user_id', $guardian->id)->where('type', 'guardian_approval_requested')->where('channel', 'email')->count());
        $this->assertSame('mail_transport_not_delivering', $this->delivery($guardian, 'guardian_approval_requested', 'email')->reason);
        // The learner is told why it waits; the tutor hears nothing about a request a guardian has not allowed yet.
        $this->assertCount(1, $this->inbox($learner, 'lesson_awaiting_guardian'));
        $this->assertCount(0, $this->inbox($tutor->user));

        $this->actingAs($guardian)->post(route('safety.guardian.approvals.decide', $ask->data['approval_id']), ['decision' => 'approve'])->assertRedirect();
        $this->assertSame('pending', TutorSession::find($id)->status);

        $tutorNotice = $this->inbox($tutor->user, 'lesson_requested')->sole();
        $this->assertStringContainsString('Sipho asked', $tutorNotice->data['message']);
        $this->assertStringNotContainsString('Dlamini', json_encode($tutorNotice->data));
        $this->assertStringNotContainsString($learner->email, json_encode($tutorNotice->data));
        $this->assertTrue($tutorNotice->data['about_minor']);
        // The guardian decided it themselves: no "asked for a lesson" copy back to them.
        $this->assertCount(0, $this->inbox($guardian, 'lesson_requested'));
        $this->assertCount(1, $this->inbox($learner, 'guardian_approval_decided'));
    }

    public function test_a_guardian_declining_tells_the_learner_once_and_the_tutor_never_hears(): void
    {
        [$tutor, $subject, $offering] = $this->bookableTutor(['price_minor' => 0]);
        $learner = $this->teen('Sipho');
        $guardian = $this->guardianFor($learner);
        $this->screen($tutor->user);
        $id = $this->book($learner, $offering, $subject, $this->slotAt($tutor, 2))->json('data.id');
        $ask = $this->inbox($guardian, 'guardian_approval_requested')->sole();

        $this->actingAs($guardian)->post(route('safety.guardian.approvals.decide', $ask->data['approval_id']), ['decision' => 'decline', 'note' => 'Not this week.'])->assertRedirect();

        $this->assertSame('cancelled', TutorSession::find($id)->status);
        $this->assertCount(1, $this->inbox($learner, 'guardian_approval_decided'));
        $this->assertCount(0, $this->inbox($learner, 'lesson_cancelled'));
        $this->assertCount(0, $this->inbox($tutor->user));
    }

    public function test_a_teens_confirmed_lesson_is_copied_to_the_guardian(): void
    {
        [$tutor, $subject, $offering] = $this->bookableTutor(['price_minor' => 0]);
        $learner = $this->teen('Sipho Dlamini');
        $guardian = $this->guardianFor($learner, $this->adult('Nomsa Dlamini'));
        $link = GuardianLink::where('learner_id', $learner->id)->sole();
        app(Guardians::class)->setTeenApprovals($link, ['booking' => false, 'purchase' => true, 'paid_room' => true, 'live_lesson' => false], $guardian);
        $this->screen($tutor->user);

        $id = $this->book($learner, $offering, $subject, $this->slotAt($tutor, 2))->json('data.id');
        $this->actingAs($tutor->user)->postJson("/api/tutor/bookings/{$id}/accept")->assertOk();

        $this->assertSame('Sipho\'s lesson is confirmed', $this->inbox($guardian, 'lesson_confirmed')->sole()->data['title']);
        $this->assertTrue($this->inbox($learner, 'lesson_confirmed')->sole()->data['about_minor']);
    }

    public function test_comms_and_marketplace_ask_the_safety_domain_about_minors(): void
    {
        $this->assertInstanceOf(SafetyMinorRouting::class, app(MinorRouting::class));
        $this->assertInstanceOf(SafetyGuardianApprovals::class, app(GuardianApprovalsPort::class));
    }
}
