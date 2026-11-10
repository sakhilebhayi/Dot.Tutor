<?php

namespace Tests\Feature\Acceptance;

use App\Models\TutorSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Brief section 25, student journey, over HTTP with every port bound to its owner: learning profile (country,
 * curriculum, level, languages), a need in plain words, explained matches, a booking, Paystack test-mode payment
 * (faked HTTP, signed webhook), the lesson, a review, and progress from practice the tutor assigns.
 */
class StudentJourneyTest extends TestCase
{
    use BuildsJourneys;
    use RefreshDatabase;

    public function test_the_student_journey(): void
    {
        $this->setUpWorld();
        [$tutorUser, $profile, $offeringId] = $this->tutorReadyToTeach('Thandeka Mokoena');
        $learner = $this->person('Sipho Dlamini');

        // 1. Profile: South Africa, CAPS Grade 11, Mathematics, taught in isiZulu or English, adult (19).
        $this->learnerProfile($learner, 19);

        // 2. The need in plain words, parsed into structure (deterministic parser, no AI).
        $need = $this->actingAs($learner)->postJson(route('academic.describe'), ['text' => 'Grade 11 Maths in isiZulu'])->assertOk()->json();
        $levelId = $this->level('za-caps', 'grade-11')->id;
        $this->assertContains($this->subject('mathematics')->id, array_column($need['subjects'] ?? [], 'id'));
        $this->assertContains($levelId, array_column($need['levels'] ?? [], 'id'));

        // 3. Matches with reasons: scope, curriculum and language come from the real academic data now.
        $match = $this->actingAs($learner)->postJson('/api/tutor/match', [
            'subject_id' => $this->subject('mathematics')->id, 'level_id' => $levelId,
            'curriculum_id' => $this->level('za-caps', 'grade-11')->curriculum_id, 'languages' => ['zu'],
            'budget_per_hour_minor' => 30000, 'currency' => 'ZAR',
        ])->assertOk();
        $this->assertSame([], $match->json('data.notes') ?? $match->json('notes'), 'Nothing is reported as "not connected"');
        $result = collect($match->json('data.results') ?? $match->json('results'))->firstWhere('tutor.id', $profile->id);
        $this->assertNotNull($result, 'The tutor is matched');
        $terms = collect($result['terms'])->keyBy('term');
        $this->assertEquals(1.0, $terms['scope']['value'], 'Grade 11 sits inside the tutor\'s Grades 10 to 12');
        $this->assertEquals(1.0, $terms['curriculum']['value']);
        $this->assertEquals(1.0, $terms['language']['value'], 'Teaches in isiZulu');
        $this->assertNotEmpty($result['reasons']);

        // 4. Book; the tutor accepts; the booking waits for payment.
        $bookingId = $this->requestLesson($learner, $offeringId, $profile)->assertCreated()->assertJsonPath('data.state', 'requested')->json('data.id');
        $this->actingAs($tutorUser)->postJson("/api/tutor/bookings/{$bookingId}/accept")->assertOk()->assertJsonPath('data.state', 'awaiting_payment');

        // 5. Pay: a money order priced from the booking snapshot, a Paystack checkout, then a signed webhook.
        $pay = $this->actingAs($learner)->postJson("/api/tutor/bookings/{$bookingId}/pay")->assertOk()
            ->assertJsonPath('payment.status', 'pending');
        $this->assertStringStartsWith('https://checkout.paystack.com/', $pay->json('payment.redirect_url'));
        $order = $this->paystackConfirmsLatestPayment();
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame(25000, (int) $order->total_minor);
        $session = TutorSession::query()->findOrFail($bookingId);
        $this->assertSame('confirmed', $session->status);
        $this->assertSame('paid', $session->payment_status);
        $this->assertSame($order->uuid, $session->order_reference);

        // 6. The lesson happens.
        $this->travelTo($session->starts_at->copy()->addMinutes(5));
        $this->actingAs($tutorUser)->postJson("/api/tutor/bookings/{$bookingId}/start")->assertOk();
        $this->travelTo($session->starts_at->copy()->addMinutes(65));
        $this->actingAs($learner)->postJson("/api/tutor/bookings/{$bookingId}/complete")->assertOk();
        $this->assertSame('completed', $session->fresh()->status);

        // 7. Review (completed bookings only).
        $this->actingAs($learner)->postJson("/api/tutor/bookings/{$bookingId}/reviews", ['rating' => 5, 'review' => 'Clear explanations of functions.'])
            ->assertCreated();
        $this->actingAs($learner)->getJson("/api/tutor/tutors/{$profile->id}/reviews")->assertOk()->assertJsonPath('data.0.rating', 5);

        // 8. Progress: the tutor (who has taught this learner) assigns practice on a CAPS topic; the learner's
        // progress shows the attempt and the topic.
        $topic = \App\Domain\Academic\Models\Topic::query()->whereHas('offering', fn ($q) => $q->where('academic_level_id', $levelId))->firstOrFail();
        $bank = $this->actingAs($tutorUser)->postJson('/api/tutor/learning/banks', [
            'title' => $topic->name, 'topic' => $topic->name, 'topic_id' => $topic->id, 'subject_id' => $this->subject('mathematics')->id,
        ])->assertCreated()->json('data.id');
        $question = $this->postJson("/api/tutor/learning/banks/{$bank}/questions", [
            'type' => 'numeric', 'prompt' => 'Solve 2x = 8', 'answer' => ['value' => 4, 'tolerance' => 0],
        ])->assertCreated()->json('data.id');
        $assessment = $this->postJson('/api/tutor/learning/assessments', ['title' => 'After our lesson', 'kind' => 'tutor_assessment'])->assertCreated()->json('data.id');
        $this->postJson("/api/tutor/learning/assessments/{$assessment}/questions", ['question_ids' => [$question]])->assertOk();
        $this->postJson("/api/tutor/learning/assessments/{$assessment}/assign", ['learner_id' => $learner->id])->assertCreated();

        $attempt = $this->actingAs($learner)->postJson("/api/tutor/learning/assessments/{$assessment}/attempts")->assertCreated()->json('data.attempt_id');
        $this->postJson("/api/tutor/learning/attempts/{$attempt}/submit", ['answers' => [$question => '4']])->assertOk()->assertJsonPath('data.percent', 100);
        $progress = $this->getJson('/api/tutor/learning/progress')->assertOk()->json('data');
        $this->assertContains($topic->name, array_column($progress['mastery'], 'topic'));
        $this->assertNotEmpty($progress['recent']);

        $this->assertLedgerHealthy();
    }
}
