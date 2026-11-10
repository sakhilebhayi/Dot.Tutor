<?php

namespace Tests\Feature\Acceptance;

use App\Domain\Marketplace\Models\TutorQualification;
use App\Domain\Money\Models\Earning;
use App\Domain\Safety\Models\AuditEntry;
use App\Models\TutorSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Brief section 25, tutor journey, over HTTP with real bindings: profile, teaching scope, teaching languages,
 * qualification with evidence, operator verification (audited in the safety log), availability, an offering after
 * the commission preview, a booking accepted and delivered, earnings pending then available, and a payout batch.
 */
class TutorJourneyTest extends TestCase
{
    use BuildsJourneys;
    use RefreshDatabase;

    public function test_the_tutor_journey(): void
    {
        $this->setUpWorld();

        // Profile, scope, languages, availability, commission preview and offering (shared steps, asserted inside).
        [$tutorUser, $profile, $offeringId] = $this->tutorReadyToTeach('Lerato Khumalo', 30000);

        // A scope outside the academic structure is refused by the real academic seam.
        $this->actingAs($tutorUser)->putJson('/api/tutor/me/scopes', ['scopes' => [[
            'subject_id' => $this->subject('mathematics')->id,
            'level_min_id' => $this->level('za-caps', 'grade-12')->id, 'level_max_id' => $this->level('za-caps', 'grade-10')->id,
        ]]])->assertStatus(422);

        // Qualification with evidence, submitted for review.
        $qualificationId = $this->actingAs($tutorUser)->postJson('/api/tutor/me/qualifications', [
            'kind' => 'degree', 'title' => 'BSc Mathematics', 'institution' => 'University of the Witwatersrand', 'year_awarded' => 2019,
        ])->assertCreated()->json('data.id');
        $pdf = UploadedFile::fake()->createWithContent('degree.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
        $this->actingAs($tutorUser)->post("/api/tutor/me/qualifications/{$qualificationId}/evidence", ['file' => $pdf], ['Accept' => 'application/json'])->assertCreated();
        $this->actingAs($tutorUser)->postJson("/api/tutor/me/qualifications/{$qualificationId}/submit")->assertJsonPath('data.status', 'submitted');

        // Operator verification: the evidence, then a level it supports. Both land in the safety audit log.
        $this->actingAs($this->operator)->postJson("/api/tutor/operator/qualifications/{$qualificationId}/verify", ['note' => 'Matches the Wits register.'])
            ->assertOk()->assertJsonPath('data.status', 'verified');
        $this->actingAs($this->operator)->postJson("/api/tutor/operator/tutors/{$profile->id}/verification-level", ['level' => 'verified_qualified_tutor', 'reason' => 'Degree verified.'])
            ->assertOk()->assertJsonPath('data.label', 'Verified qualified tutor');
        $this->assertTrue(AuditEntry::query()->where('action', 'marketplace.verification.level')->exists());
        $this->assertTrue(AuditEntry::query()->where('action', 'marketplace.verification.qualification')->exists());
        $this->assertSame('verified', TutorQualification::query()->findOrFail($qualificationId)->status);

        // The storefront shows the verified label and the teaching languages from the academic profile.
        $store = $this->actingAs($this->person('Visitor'))->getJson("/api/tutor/tutors/{$profile->id}")->assertOk();
        $this->assertContains('zu', array_column($store->json('data.languages') ?? [], 'code'));

        // A learner books; the tutor accepts; payment through Paystack; the lesson is delivered.
        $learner = $this->person('Anele Ngcobo');
        $this->learnerProfile($learner, 20);
        $bookingId = $this->requestLesson($learner, $offeringId, $profile)->assertCreated()->json('data.id');
        $this->actingAs($tutorUser)->postJson("/api/tutor/bookings/{$bookingId}/accept")->assertOk()->assertJsonPath('data.state', 'awaiting_payment');
        $this->actingAs($learner)->postJson("/api/tutor/bookings/{$bookingId}/pay")->assertOk();
        $this->paystackConfirmsLatestPayment();
        $session = TutorSession::query()->findOrFail($bookingId);
        $this->assertSame('confirmed', $session->status);

        // Earnings: pending while the lesson is ahead and during the complaint hold.
        $this->actingAs($tutorUser)->getJson('/api/tutor/money/tutor/earnings')->assertOk()
            ->assertJsonPath('balances.pending.amount_minor', 25500)->assertJsonPath('balances.available.amount_minor', 0);

        $this->travelTo($session->starts_at->copy()->addMinutes(5));
        $this->actingAs($tutorUser)->postJson("/api/tutor/bookings/{$bookingId}/start")->assertOk();
        $this->travelTo($session->starts_at->copy()->addMinutes(65));
        $this->actingAs($tutorUser)->postJson("/api/tutor/bookings/{$bookingId}/complete")->assertOk();

        $this->artisan('money:settle')->assertSuccessful();
        $this->assertSame(Earning::PENDING, Earning::query()->sole()->status, 'Held for complaints after delivery');

        $this->travel(25)->hours();
        $this->artisan('money:settle')->assertSuccessful();
        $this->actingAs($tutorUser)->getJson('/api/tutor/money/tutor/earnings')->assertOk()
            ->assertJsonPath('balances.available.amount_minor', 25500)->assertJsonPath('balances.pending.amount_minor', 0);

        // Payout: the tutor's bank account, a finance batch, paid.
        $this->actingAs($tutorUser)->putJson('/api/tutor/money/tutor/payout-account', [
            'holder_name' => 'L Khumalo', 'account_number' => '62001234567', 'bank_name' => 'First National Bank', 'bank_code' => '250655',
        ])->assertOk();
        $batch = $this->actingAs($this->operator)->postJson('/api/tutor/money/ops/payout-batches')->assertCreated()
            ->assertJsonPath('batch.payout_count', 1)->json('batch.id');
        $payoutId = $this->actingAs($this->operator)->getJson("/api/tutor/money/ops/payout-batches/{$batch}")->assertOk()->json('payouts.0.id');
        $this->actingAs($this->operator)->postJson("/api/tutor/money/ops/payouts/{$payoutId}/paid")->assertOk()->assertJsonPath('payout.status', 'paid');
        $this->actingAs($tutorUser)->getJson('/api/tutor/money/tutor/earnings')->assertOk()->assertJsonPath('balances.paid_out.amount_minor', 25500);
        $this->assertTrue(AuditEntry::query()->where('action', 'like', 'money.%')->exists(), 'Money actions are in the safety audit log');

        $this->assertLedgerHealthy();
        $this->artisan('safety:audit-verify')->assertSuccessful();
    }
}
