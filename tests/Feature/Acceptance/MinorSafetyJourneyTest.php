<?php

namespace Tests\Feature\Acceptance;

use App\Domain\Money\Models\Order;
use App\Domain\Safety\Enums\ScreeningKind;
use App\Domain\Safety\Models\GuardianApproval;
use App\Domain\Safety\Models\GuardianInvitation;
use App\Domain\Safety\Models\Incident;
use App\Domain\Safety\Models\Message;
use App\Domain\Safety\Models\ScreeningCheck;
use App\Models\TutorSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Brief section 25, minor safety journey, across domains with real bindings: the child's age comes from the
 * academic learner profile; the account is limited until a guardian consents; a marketplace booking is held for
 * the guardian and released by the guardian's decision on the safety dashboard; the payment is held by money until
 * the guardian approves the purchase; an adult cannot open a direct conversation with the child; contact details
 * are redacted in the booking conversation; a report about them takes the child-safety fast path.
 */
class MinorSafetyJourneyTest extends TestCase
{
    use BuildsJourneys;
    use RefreshDatabase;

    public function test_the_minor_safety_journey(): void
    {
        $this->setUpWorld();
        Notification::fake();
        [$tutorUser, $profile, $offeringId] = $this->tutorReadyToTeach('Mpho Sithole');

        // 1. A child account: the age band is computed from the birth month on the academic learner profile.
        $child = $this->person('Lwazi');
        $this->learnerProfile($child, 10, 'za-caps', 'grade-5');
        $this->actingAs($child)->getJson(route('safety.account'))->assertOk()
            ->assertJsonPath('account.age_band', 'child')->assertJsonPath('account.status', 'limited');

        // Limited: no booking before a guardian consents.
        $this->requestLesson($child, $offeringId, $profile)->assertForbidden()->assertJsonPath('error', 'safeguarding');
        $this->assertSame(0, TutorSession::query()->count());

        // 2. Guardian invitation and consent.
        $guardian = $this->person('Nomvula');
        $url = $this->actingAs($child)->postJson(route('safety.guardians.invite'), ['email' => $guardian->email, 'relationship' => 'parent'])
            ->assertCreated()->json('accept_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->actingAs($guardian)->get($url)->assertOk();
        $this->actingAs($guardian)->postJson(route('safety.guardians.accept.store', GuardianInvitation::query()->sole()->id), [
            'token' => $query['token'], 'birth_date' => now()->subYears(10)->subMonths(2)->toDateString(), 'consent' => '1',
        ])->assertCreated();
        $this->actingAs($child)->getJson(route('safety.account'))->assertJsonPath('account.status', 'active');

        // An unscreened tutor still cannot take the child's booking.
        $this->requestLesson($child, $offeringId, $profile)->assertForbidden()->assertJsonPath('error', 'safeguarding');
        $this->recordScreening($tutorUser);

        // 3. The booking is held for the guardian; the tutor cannot accept it yet.
        $booking = $this->requestLesson($child, $offeringId, $profile, 4)->assertCreated()
            ->assertJsonPath('data.state', 'awaiting_guardian')->assertJsonPath('data.guardian_status', 'pending');
        $this->assertStringContainsString('asked your parent or guardian', (string) $booking->json('notice'));
        $bookingId = $booking->json('data.id');
        $this->actingAs($tutorUser)->postJson("/api/tutor/bookings/{$bookingId}/accept")->assertStatus(409);

        // 4. The guardian approves on the safety dashboard; the marketplace booking is released to the tutor.
        $approval = GuardianApproval::query()->where('kind', 'booking')->sole();
        $this->actingAs($guardian)->getJson(route('safety.guardian.dashboard'))->assertOk()
            ->assertJsonPath('children.0.approvals.0.id', $approval->id)->assertJsonPath('children.0.approvals.0.status', 'pending');
        $this->actingAs($guardian)->postJson(route('safety.guardian.approvals.decide', $approval), ['decision' => 'approve'])->assertOk();
        $this->assertSame('pending', TutorSession::query()->findOrFail($bookingId)->status, 'requested (stored as pending)');
        $this->assertSame('approved', TutorSession::query()->findOrFail($bookingId)->guardian_status);
        $this->actingAs($tutorUser)->postJson("/api/tutor/bookings/{$bookingId}/accept")->assertOk()->assertJsonPath('data.state', 'awaiting_payment');

        // 5. Payment needs the guardian too: money holds the order and asks; nothing reaches Paystack.
        $this->actingAs($child)->postJson("/api/tutor/bookings/{$bookingId}/pay")->assertOk()->assertJsonPath('payment.status', 'awaiting_guardian');
        $this->assertSame(0, $this->paystack->calls('initialize'));
        $order = Order::query()->sole();
        $this->assertSame(Order::AWAITING_GUARDIAN, $order->status);
        $purchase = GuardianApproval::query()->where('kind', 'purchase')->sole();
        $this->actingAs($guardian)->getJson(route('safety.guardian.dashboard'))->assertJsonPath('children.0.purchases.0.amount_minor', 25000);
        $this->actingAs($guardian)->postJson(route('safety.guardian.approvals.decide', $purchase), ['decision' => 'approve'])->assertOk();
        $this->assertSame(Order::PENDING_PAYMENT, $order->fresh()->status);

        // The guardian (or the child) pays; Paystack confirms; the booking is confirmed.
        $this->actingAs($guardian)->postJson("/api/tutor/money/orders/{$order->uuid}/pay")->assertOk()->assertJsonPath('status', 'redirect');
        $this->paystackConfirmsLatestPayment();
        $this->assertSame('confirmed', TutorSession::query()->findOrFail($bookingId)->status);

        // 6. An adult cannot open a direct conversation with the child.
        $this->actingAs($tutorUser)->postJson(route('safety.conversations.store'), ['context_type' => 'direct', 'recipient_ids' => [$child->id], 'body' => 'Hi'])
            ->assertForbidden()->assertJsonPath('reasons.0.code', 'minor_needs_context');

        // 7. Inside the booking, contact details are hidden, and the guardian reads along.
        $conversationId = $this->actingAs($tutorUser)->postJson(route('safety.conversations.store'), [
            'context_type' => 'booking', 'context_id' => $bookingId, 'body' => 'WhatsApp me on 082 555 1234 instead',
        ])->assertCreated()->assertJsonPath('message.redacted', true)->json('conversation_id');
        $shown = $this->actingAs($guardian)->getJson(route('safety.conversations.show', $conversationId))->assertOk();
        $this->assertStringNotContainsString('082 555 1234', $shown->getContent());

        // 8. The guardian reports it: critical priority and an incident opened at once.
        $this->actingAs($guardian)->postJson(route('safety.messages.report', Message::query()->sole()), [
            'category' => 'inappropriate_contact_minor', 'details' => 'Trying to move my child off the platform.',
        ])->assertCreated()->assertJsonPath('report.priority', 'critical');
        $this->assertSame(1, Incident::query()->count());
        $this->travel(61)->minutes();
        $this->artisan('safety:escalate-incidents')->assertSuccessful();
        $this->assertSame(1, Incident::query()->sole()->escalation_level);

        $this->assertLedgerHealthy();
        $this->artisan('safety:audit-verify')->assertSuccessful();
    }

    /** The operator's off-platform screening record (no automated register exists); two operators, four eyes. */
    private function recordScreening(User $tutor): void
    {
        $reviewer = $this->makeOperator();
        foreach (config('safety.screening.required_kinds') as $kind) {
            ScreeningCheck::create([
                'user_id' => $tutor->id, 'kind' => ScreeningKind::from($kind), 'status' => ScreeningCheck::CLEARED,
                'checked_on' => now()->subDay()->toDateString(), 'evidence_path' => 'screening/'.$tutor->id.'/'.$kind.'.pdf',
                'recorded_by_id' => $this->operator->id, 'reviewed_by_id' => $reviewer->id, 'reviewed_at' => now(),
            ]);
        }
    }
}
