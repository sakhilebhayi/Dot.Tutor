<?php

namespace Tests\Feature\Marketplace;

use App\Domain\Marketplace\Contracts\SafeguardingPort;
use App\Domain\Marketplace\Models\TutorSessionEvent;
use App\Domain\Marketplace\Services\BookingFlow;
use App\Domain\Marketplace\Support\GateDecision;
use App\Models\TutorSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Concerns\BuildsOfferings;
use Tests\TestCase;

class BookingFlowTest extends TestCase
{
    use BuildsOfferings;
    use RefreshDatabase;

    private function book($learner, $offering, $subject, $start, array $headers = [], array $extra = [])
    {
        return $this->actingAs($learner)->withHeaders($headers)->postJson("/api/tutor/offerings/{$offering->id}/bookings", array_merge([
            'subject_id' => $subject->id,
            'starts_at' => $start->format('Y-m-d\TH:i:s\Z'),
        ], $extra));
    }

    public function test_a_learner_requests_a_lesson_and_the_tutor_accepts_it_into_awaiting_payment(): void
    {
        [$tutor, $subject, $offering] = $this->bookableTutor();
        $learner = $this->makeUser('Learner');

        $response = $this->book($learner, $offering, $subject, $this->slotAt($tutor, 2))->assertCreated();
        $response->assertJsonPath('data.state', 'requested')
            ->assertJsonPath('data.price.amount_minor', 30000)
            ->assertJsonPath('data.payment_status', 'unpaid')
            ->assertJsonPath('replayed', false);
        $id = $response->json('data.id');

        $this->actingAs($tutor->user)->postJson("/api/tutor/bookings/{$id}/accept")
            ->assertOk()
            ->assertJsonPath('data.state', 'awaiting_payment');

        $this->assertNotNull(TutorSession::find($id)->hold_expires_at);
        $this->assertSame(['requested', 'accepted'], TutorSessionEvent::where('tutor_session_id', $id)->orderBy('id')->pluck('event')->all());
    }

    public function test_paying_without_a_configured_provider_says_so_and_charges_nothing(): void
    {
        [$tutor, $subject, $offering] = $this->bookableTutor();
        $learner = $this->makeUser();
        $id = $this->book($learner, $offering, $subject, $this->slotAt($tutor, 2))->json('data.id');
        $this->actingAs($tutor->user)->postJson("/api/tutor/bookings/{$id}/accept")->assertOk();

        $this->actingAs($learner)->postJson("/api/tutor/bookings/{$id}/pay")
            ->assertStatus(503)
            ->assertJsonPath('error', 'payments_not_configured')
            ->assertJsonPath('payment.status', 'unconfigured');

        $this->assertSame('awaiting_payment', TutorSession::find($id)->status);
        $this->assertSame('unpaid', TutorSession::find($id)->payment_status);
    }

    public function test_a_verified_payment_confirms_the_booking(): void
    {
        $checkout = $this->fakePayingCheckout();
        [$tutor, $subject, $offering] = $this->bookableTutor();
        $learner = $this->makeUser();
        $id = $this->book($learner, $offering, $subject, $this->slotAt($tutor, 2))->json('data.id');
        $this->actingAs($tutor->user)->postJson("/api/tutor/bookings/{$id}/accept");

        $this->actingAs($learner)->postJson("/api/tutor/bookings/{$id}/pay")->assertOk()->assertJsonPath('payment.status', 'paid');

        $session = TutorSession::find($id);
        $this->assertSame('confirmed', $session->status);
        $this->assertSame('paid', $session->payment_status);
        $this->assertSame(30000, $checkout->calls[0][0]['amount_minor']);
        $this->assertSame($tutor->user_id, $checkout->calls[0][0]['seller_user_id']);
    }

    public function test_a_free_offering_is_confirmed_on_acceptance(): void
    {
        [$tutor, $subject, $offering] = $this->bookableTutor(['price_minor' => 0, 'type' => 'consultation', 'duration_minutes' => 15]);
        $id = $this->book($this->makeUser(), $offering, $subject, $this->slotAt($tutor, 2))->json('data.id');

        $this->actingAs($tutor->user)->postJson("/api/tutor/bookings/{$id}/accept")->assertJsonPath('data.state', 'confirmed');
    }

    public function test_only_the_learner_can_pay_and_only_the_tutor_can_accept(): void
    {
        [$tutor, $subject, $offering] = $this->bookableTutor();
        $learner = $this->makeUser();
        $stranger = $this->makeUser('Stranger');
        $id = $this->book($learner, $offering, $subject, $this->slotAt($tutor, 2))->json('data.id');

        $this->actingAs($learner)->postJson("/api/tutor/bookings/{$id}/accept")->assertForbidden()->assertJsonPath('error', 'forbidden');
        $this->actingAs($stranger)->getJson("/api/tutor/bookings/{$id}")->assertForbidden();
        $this->actingAs($tutor->user)->postJson("/api/tutor/bookings/{$id}/accept");
        $this->actingAs($stranger)->postJson("/api/tutor/bookings/{$id}/pay")->assertForbidden();
    }

    public function test_a_minor_is_held_for_a_guardian_and_the_null_seam_never_approves(): void
    {
        [$tutor, $subject, $offering] = $this->bookableTutor();
        $child = $this->makeUser('Child');
        $this->fakeMinors($child);

        $response = $this->book($child, $offering, $subject, $this->slotAt($tutor, 2))->assertCreated();
        $response->assertJsonPath('data.state', 'awaiting_guardian')
            ->assertJsonPath('data.guardian_status', 'pending');
        $this->assertStringContainsString('guardian approvals are not available', $response->json('notice'));

        // The tutor cannot accept a request still waiting for a guardian.
        $this->actingAs($tutor->user)->postJson('/api/tutor/bookings/'.$response->json('data.id').'/accept')
            ->assertStatus(409)->assertJsonPath('error', 'invalid_transition');
    }

    public function test_a_guardian_decision_releases_or_cancels_the_hold(): void
    {
        [$tutor, $subject, $offering] = $this->bookableTutor();
        $child = $this->makeUser('Child');
        $this->fakeMinors($child);
        $first = TutorSession::find($this->book($child, $offering, $subject, $this->slotAt($tutor, 2))->json('data.id'));
        $second = TutorSession::find($this->book($child, $offering, $subject, $this->slotAt($tutor, 3))->json('data.id'));

        app(BookingFlow::class)->applyGuardianDecision($first, true);
        app(BookingFlow::class)->applyGuardianDecision($second, false, null, 'Not this week.');

        $this->assertSame('pending', $first->fresh()->status);
        $this->assertSame('approved', $first->fresh()->guardian_status);
        $this->assertSame('cancelled', $second->fresh()->status);
    }

    public function test_the_tutor_declines_with_a_reason(): void
    {
        [$tutor, $subject, $offering] = $this->bookableTutor();
        $id = $this->book($this->makeUser(), $offering, $subject, $this->slotAt($tutor, 2))->json('data.id');

        $this->actingAs($tutor->user)->postJson("/api/tutor/bookings/{$id}/decline", [])->assertStatus(422)->assertJsonPath('error', 'validation_failed');
        $this->actingAs($tutor->user)->postJson("/api/tutor/bookings/{$id}/decline", ['reason' => 'I do not teach that syllabus.'])
            ->assertOk()->assertJsonPath('data.state', 'cancelled')->assertJsonPath('data.decision_reason', 'I do not teach that syllabus.');
    }

    public function test_cancellation_policy_sets_the_refund_due(): void
    {
        $this->fakePayingCheckout();
        [$tutor, $subject, $offering] = $this->bookableTutor(['cancellation_notice_hours' => 48, 'late_cancellation_refund_percent' => 25]);
        $learner = $this->makeUser();

        // Late: 1 day ahead, notice is 48 hours.
        $late = $this->book($learner, $offering, $subject, $this->slotAt($tutor, 1, '18:00'))->json('data.id');
        $this->actingAs($tutor->user)->postJson("/api/tutor/bookings/{$late}/accept");
        $this->actingAs($learner)->postJson("/api/tutor/bookings/{$late}/pay");
        $this->actingAs($learner)->postJson("/api/tutor/bookings/{$late}/cancel")->assertOk()->assertJsonPath('data.state', 'cancelled');
        $event = TutorSessionEvent::where('tutor_session_id', $late)->where('event', 'cancelled')->first();
        $this->assertSame(25, $event->context['refund_due_percent']);
        $this->assertSame(7500, $event->context['refund_due_minor']);

        // In time: 5 days ahead.
        $early = $this->book($learner, $offering, $subject, $this->slotAt($tutor, 5))->json('data.id');
        $this->actingAs($tutor->user)->postJson("/api/tutor/bookings/{$early}/accept");
        $this->actingAs($learner)->postJson("/api/tutor/bookings/{$early}/pay");
        $this->actingAs($learner)->postJson("/api/tutor/bookings/{$early}/cancel")->assertOk();
        $this->assertSame(100, TutorSessionEvent::where('tutor_session_id', $early)->where('event', 'cancelled')->first()->context['refund_due_percent']);
    }

    public function test_a_retry_with_the_same_idempotency_key_books_once(): void
    {
        [$tutor, $subject, $offering] = $this->bookableTutor();
        $learner = $this->makeUser();
        $start = $this->slotAt($tutor, 2);

        $first = $this->book($learner, $offering, $subject, $start, ['Idempotency-Key' => 'abc-123'])->assertCreated();
        $second = $this->book($learner, $offering, $subject, $start, ['Idempotency-Key' => 'abc-123'])->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertTrue($second->json('replayed'));
        $this->assertSame(1, TutorSession::count());
    }

    public function test_reusing_a_key_for_a_different_request_is_refused(): void
    {
        [$tutor, $subject, $offering] = $this->bookableTutor();
        $learner = $this->makeUser();

        $this->book($learner, $offering, $subject, $this->slotAt($tutor, 2), ['Idempotency-Key' => 'k1'])->assertCreated();
        $this->book($learner, $offering, $subject, $this->slotAt($tutor, 3), ['Idempotency-Key' => 'k1'])
            ->assertStatus(422)->assertJsonPath('error', 'idempotency_mismatch');
        $this->assertSame(1, TutorSession::count());
    }

    public function test_a_failed_request_frees_its_key_for_a_retry(): void
    {
        [$tutor, $subject, $offering] = $this->bookableTutor();
        $learner = $this->makeUser();
        $this->makeSession($tutor, $this->makeUser('Other'), $subject, ['starts_at' => $this->slotAt($tutor, 2)]);

        $this->book($learner, $offering, $subject, $this->slotAt($tutor, 2), ['Idempotency-Key' => 'k2'])->assertStatus(422);
        $this->book($learner, $offering, $subject, $this->slotAt($tutor, 2, '14:00'), ['Idempotency-Key' => 'k2'])->assertCreated();
    }

    public function test_a_duplicate_request_without_a_key_is_refused_as_a_clash(): void
    {
        [$tutor, $subject, $offering] = $this->bookableTutor();
        $learner = $this->makeUser();
        $start = $this->slotAt($tutor, 2);

        $this->book($learner, $offering, $subject, $start)->assertCreated();
        $this->book($learner, $offering, $subject, $start)->assertStatus(422)->assertJsonPath('error', 'validation_failed');
        $this->assertSame(1, TutorSession::count());
    }

    public function test_bookings_respect_open_hours_and_the_offering_subjects(): void
    {
        [$tutor, $subject, $offering] = $this->bookableTutor();
        $other = $this->makeSubject('Chemistry');
        $learner = $this->makeUser();

        $this->book($learner, $offering, $subject, $this->slotAt($tutor, 2, '22:00'))->assertStatus(422)->assertJsonValidationErrors('starts_at');
        $this->actingAs($learner)->postJson("/api/tutor/offerings/{$offering->id}/bookings", [
            'subject_id' => $other->id, 'starts_at' => $this->slotAt($tutor, 2)->toIso8601String(),
        ])->assertStatus(422)->assertJsonValidationErrors('subject_id');
    }

    public function test_an_unapproved_tutor_or_archived_offering_cannot_be_booked(): void
    {
        [$tutor, $subject, $offering] = $this->bookableTutor();
        $offering->update(['is_active' => false]);
        $this->book($this->makeUser(), $offering, $subject, $this->slotAt($tutor, 2))->assertNotFound()->assertJsonPath('error', 'not_bookable');

        [$pending, $subject2, $offering2] = $this->bookableTutor([], ['status' => 'pending']);
        $this->book($this->makeUser(), $offering2, $subject2, $this->slotAt($pending, 2))->assertNotFound();
    }

    public function test_invalid_transitions_are_refused_and_events_are_append_only(): void
    {
        [$tutor, $subject, $offering] = $this->bookableTutor();
        $learner = $this->makeUser();
        $id = $this->book($learner, $offering, $subject, $this->slotAt($tutor, 2))->json('data.id');

        $this->actingAs($tutor->user)->postJson("/api/tutor/bookings/{$id}/start")->assertStatus(409)->assertJsonPath('error', 'invalid_transition');
        $this->actingAs($learner)->postJson("/api/tutor/bookings/{$id}/cancel")->assertOk();
        $this->actingAs($learner)->postJson("/api/tutor/bookings/{$id}/cancel")->assertStatus(409);

        $event = TutorSessionEvent::first();
        $this->expectException(LogicException::class);
        $event->update(['reason' => 'rewritten']);
    }

    public function test_a_lesson_runs_from_start_to_completion_and_can_be_disputed(): void
    {
        [$tutor, $subject, $offering] = $this->bookableTutor(['price_minor' => 0]);
        $learner = $this->makeUser();
        $id = $this->book($learner, $offering, $subject, $this->slotAt($tutor, 1))->json('data.id');
        $this->actingAs($tutor->user)->postJson("/api/tutor/bookings/{$id}/accept")->assertJsonPath('data.state', 'confirmed');

        $this->travelTo(TutorSession::find($id)->starts_at->copy()->addMinutes(5));
        $this->actingAs($tutor->user)->postJson("/api/tutor/bookings/{$id}/start")->assertJsonPath('data.state', 'in_progress');
        $this->actingAs($learner)->postJson("/api/tutor/bookings/{$id}/complete")->assertJsonPath('data.state', 'completed');
        // A second "complete" (the other party, a double click) is counted once.
        $this->actingAs($tutor->user)->postJson("/api/tutor/bookings/{$id}/complete")->assertOk()->assertJsonPath('already_completed', true);
        $this->assertSame(1, $tutor->fresh()->total_sessions);

        $this->actingAs($learner)->postJson("/api/tutor/bookings/{$id}/dispute", ['reason' => 'The tutor left after ten minutes.'])
            ->assertJsonPath('data.state', 'disputed');

        $operator = $this->makeOperator();
        $this->actingAs($operator)->postJson("/api/tutor/operator/bookings/{$id}/resolve-dispute", [
            'outcome' => 'cancelled', 'refund_percent' => 100, 'reason' => 'Tutor confirmed leaving early.',
        ])->assertOk()->assertJsonPath('data.state', 'cancelled');
        $this->actingAs($learner)->postJson("/api/tutor/operator/bookings/{$id}/resolve-dispute", [])->assertForbidden();
    }

    public function test_the_legacy_confirm_button_respects_payment_on_a_v2_booking(): void
    {
        [$tutor, $subject, $offering] = $this->bookableTutor();
        $id = $this->book($this->makeUser(), $offering, $subject, $this->slotAt($tutor, 2))->json('data.id');

        $this->actingAs($tutor->user)->post("/sessions/{$id}/confirm")->assertRedirect();

        $this->assertSame('awaiting_payment', TutorSession::find($id)->status);
    }

    public function test_an_unpaid_hold_expires_and_frees_the_time(): void
    {
        [$tutor, $subject, $offering] = $this->bookableTutor();
        $id = $this->book($this->makeUser(), $offering, $subject, $this->slotAt($tutor, 5))->json('data.id');
        $this->actingAs($tutor->user)->postJson("/api/tutor/bookings/{$id}/accept");

        $this->travel(49)->hours();
        $this->artisan('tutor:expire-marketplace-holds')->assertSuccessful();

        $this->assertSame('cancelled', TutorSession::find($id)->status);
        $this->assertSame('expired', TutorSessionEvent::where('tutor_session_id', $id)->orderByDesc('id')->value('event'));
    }

    public function test_safeguarding_can_refuse_a_booking(): void
    {
        $this->app->instance(SafeguardingPort::class, new class implements SafeguardingPort
        {
            public function allows(User $actor, string $ability, mixed $subject = null): GateDecision
            {
                return GateDecision::deny('account restricted');
            }
        });
        [$tutor, $subject, $offering] = $this->bookableTutor();

        $this->book($this->makeUser(), $offering, $subject, $this->slotAt($tutor, 2))
            ->assertForbidden()->assertJsonPath('error', 'safeguarding');
        $this->assertSame(0, TutorSession::count());
    }
}
