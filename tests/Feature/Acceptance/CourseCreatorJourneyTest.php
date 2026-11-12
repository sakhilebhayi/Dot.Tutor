<?php

namespace Tests\Feature\Acceptance;

use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\Enrolment;
use App\Domain\Money\Models\Earning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Brief section 25, course creator journey, over HTTP with real bindings: a course placed in the academic
 * structure (curriculum, level, subject), sections and lessons with an upload, language and price, publish, a
 * paid enrolment through money (Paystack webhook, the buyer is enrolled automatically), progress, the creator's
 * earnings, and a refund that closes the refunded learner's access.
 */
class CourseCreatorJourneyTest extends TestCase
{
    use BuildsJourneys;
    use RefreshDatabase;

    public function test_the_course_creator_journey(): void
    {
        $this->setUpWorld();
        [$creator] = $this->tutorReadyToTeach('Naledi Mahlangu');
        $grade11 = $this->level('za-caps', 'grade-11');

        // Course, placed in the academic structure; a level from another curriculum is refused by the academic seam.
        $courseId = $this->actingAs($creator)->postJson('/api/tutor/learning/studio/courses', [
            'title' => 'Grade 11 Functions in isiZulu', 'subject_id' => $this->subject('mathematics')->id, 'original_locale' => 'zu',
        ])->assertCreated()->json('data.id');
        $this->putJson("/api/tutor/learning/studio/courses/{$courseId}", [
            'curriculum_id' => $grade11->curriculum_id, 'level_id' => $this->level('cambridge', 'igcse')->id,
        ])->assertStatus(422)->assertJsonPath('error', 'validation_failed');
        $this->putJson("/api/tutor/learning/studio/courses/{$courseId}", [
            'curriculum_id' => $grade11->curriculum_id, 'level_id' => $grade11->id,
            'description' => 'Linear, quadratic and exponential functions for CAPS Grade 11.',
            'objectives' => ['Sketch a parabola from its equation'], 'workload_minutes' => 180,
            'pricing' => 'paid', 'price_minor' => 20000, 'refund_policy' => 'Full refund within 7 days.',
        ])->assertOk();

        // Sections and lessons, one of them an uploaded document.
        $section = $this->postJson("/api/tutor/learning/studio/courses/{$courseId}/sections", ['title' => 'Parabolas'])->assertCreated()->json('data.id');
        $this->postJson("/api/tutor/learning/studio/sections/{$section}/lessons", ['title' => 'The shape of y = ax^2', 'type' => 'text', 'body' => 'a > 0 opens upwards.'])->assertCreated();
        $pdf = UploadedFile::fake()->createWithContent('notes.pdf', "%PDF-1.4\n%\xe2\xe3\xcf\xd3\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
        $this->post("/api/tutor/learning/studio/sections/{$section}/lessons", ['title' => 'Worked examples', 'type' => 'document', 'file' => $pdf], ['Accept' => 'application/json'])->assertCreated();

        // Publish (approved tutor via the marketplace directory, safeguarding publish check via the safety gate).
        $this->postJson("/api/tutor/learning/studio/courses/{$courseId}/publish")->assertCreated()->assertJsonPath('data.version', 1);
        $course = Course::query()->findOrFail($courseId);
        $this->getJson("/api/tutor/learning/courses/{$courseId}")->assertOk()->assertJsonPath('data.price_minor', 20000);

        // A learner buys it: quote from money, checkout, Paystack webhook; enrolment follows the payment.
        $learner = $this->person('Bongani Zulu');
        $this->learnerProfile($learner, 22);
        $this->actingAs($learner)->getJson("/api/tutor/learning/courses/{$courseId}/quote")->assertOk()->assertJsonPath('data.payable_minor', 20000);
        $this->actingAs($learner)->postJson("/api/tutor/learning/courses/{$courseId}/enrol")->assertStatus(422);
        $checkout = $this->actingAs($learner)->postJson("/api/tutor/learning/courses/{$courseId}/checkout", [], ['Idempotency-Key' => 'buy-1'])
            ->assertStatus(202)->assertJsonPath('data.status', 'redirect');
        $this->assertStringStartsWith('https://checkout.paystack.com/', $checkout->json('data.redirect_url'));
        $order = $this->paystackConfirmsLatestPayment();
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame('active', Enrolment::query()->where('user_id', $learner->id)->where('course_id', $courseId)->sole()->status);

        // Progress through the protected lessons.
        $lessons = $course->lessons()->orderBy('position')->pluck('id')->all();
        $this->actingAs($learner)->postJson("/api/tutor/learning/courses/{$courseId}/lessons/{$lessons[0]}/complete")->assertOk();
        $this->getJson("/api/tutor/learning/courses/{$courseId}/progress")->assertOk()->assertJsonPath('data.percent', 50);
        $this->actingAs($creator)->getJson("/api/tutor/learning/studio/courses/{$courseId}/learners")->assertOk()->assertJsonPath('data.0.percent', 50);

        // Earnings: the creator's share is pending through the refund window, then available.
        $this->actingAs($creator)->getJson('/api/tutor/money/tutor/earnings')->assertOk()->assertJsonPath('balances.pending.amount_minor', 17000);
        $this->travel(8)->days();
        $this->artisan('money:settle')->assertSuccessful();
        $this->actingAs($creator)->getJson('/api/tutor/money/tutor/earnings')->assertOk()->assertJsonPath('balances.available.amount_minor', 17000);

        // A second buyer is refunded in full by finance: money revokes the entitlement, learning closes the enrolment.
        $second = $this->person('Zanele Khoza');
        $this->actingAs($second)->postJson("/api/tutor/learning/courses/{$courseId}/checkout", [], ['Idempotency-Key' => 'buy-2'])->assertStatus(202);
        $refunded = $this->paystackConfirmsLatestPayment();
        $this->actingAs($second)->getJson("/api/tutor/learning/courses/{$courseId}/learn/{$lessons[1]}")->assertOk();
        $line = $refunded->lines()->sole();
        $this->actingAs($this->operator)->withHeader('Idempotency-Key', 'refund-1')
            ->postJson("/api/tutor/money/ops/orders/{$refunded->uuid}/lines/{$line->id}/refunds", ['amount_minor' => 20000, 'reason' => 'course_refund_window'])
            ->assertCreated()->assertJsonPath('refund.status', 'processed');
        $this->assertSame('refunded', Enrolment::query()->where('user_id', $second->id)->where('course_id', $courseId)->sole()->status);
        $this->actingAs($second)->getJson("/api/tutor/learning/courses/{$courseId}/learn/{$lessons[1]}")->assertForbidden();
        $this->assertSame(2, Earning::query()->count() - Earning::query()->where('net_minor', '<', 0)->count(), 'One sale earning per paid order');

        $this->assertLedgerHealthy();
    }
}
