<?php

namespace Tests\Feature\Marketplace;

use App\Domain\Marketplace\Contracts\ReportSinkPort;
use App\Models\SessionRating;
use App\Models\TutorSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsOfferings;
use Tests\TestCase;

class ReviewsAndApiTest extends TestCase
{
    use BuildsOfferings;
    use RefreshDatabase;

    private function completed(): array
    {
        $subject = $this->makeSubject();
        $tutor = $this->makeTutor([], $subject);
        $learner = $this->makeUser('Lerato Mokoena');
        $session = $this->makeSession($tutor, $learner, $subject, ['status' => 'completed', 'starts_at' => now()->subDay()]);

        return [$tutor, $learner, $session];
    }

    public function test_only_the_learner_on_a_completed_booking_reviews_once(): void
    {
        [$tutor, $learner, $session] = $this->completed();

        $this->actingAs($tutor->user)->postJson("/api/tutor/bookings/{$session->id}/reviews", ['rating' => 5])->assertForbidden();
        $this->actingAs($learner)->postJson("/api/tutor/bookings/{$session->id}/reviews", ['rating' => 4, 'review' => 'Helpful.'])
            ->assertCreated()->assertJsonPath('data.status', 'published');
        $this->actingAs($learner)->postJson("/api/tutor/bookings/{$session->id}/reviews", ['rating' => 1])->assertForbidden();

        $pending = $this->makeSession($tutor, $learner, $tutor->subjects()->first(), ['starts_at' => now()->addDays(3)]);
        $this->actingAs($learner)->postJson("/api/tutor/bookings/{$pending->id}/reviews", ['rating' => 5])->assertForbidden();
        $this->assertSame('4.00', $tutor->fresh()->rating);
    }

    public function test_a_review_with_contact_details_is_held_and_sent_to_moderation(): void
    {
        $sink = new class implements ReportSinkPort
        {
            public array $reports = [];

            public function report(Model $reportable, ?User $reporter, string $reason, array $context = []): ?string
            {
                $this->reports[] = [$reportable->getKey(), $reason, $context['source'] ?? null];

                return 'R-'.count($this->reports);
            }
        };
        $this->app->instance(ReportSinkPort::class, $sink);
        [$tutor, $learner, $session] = $this->completed();

        $this->actingAs($learner)->postJson("/api/tutor/bookings/{$session->id}/reviews", ['rating' => 5, 'review' => 'Great! WhatsApp me on 082 555 1234'])
            ->assertCreated()->assertJsonPath('data.status', 'held');

        $this->assertSame('0.00', $tutor->fresh()->rating, 'A held review does not count.');
        $this->assertSame('automatic_screen', $sink->reports[0][2]);
        $this->actingAs($this->makeUser())->getJson("/api/tutor/tutors/{$tutor->id}/reviews")->assertJsonPath('summary.count', 0)->assertJsonCount(0, 'data');

        // The legacy rating form applies the same screen.
        [$tutor2, $learner2, $session2] = $this->completed();
        $this->actingAs($learner2)->post("/sessions/{$session2->id}/rating", ['rating' => 5, 'review' => 'mail me at a@b.co'])->assertRedirect();
        $this->assertSame('held', SessionRating::where('session_id', $session2->id)->value('status'));
    }

    public function test_three_distinct_reports_hold_a_review_until_an_operator_decides(): void
    {
        [$tutor, $learner, $session] = $this->completed();
        $id = $this->actingAs($learner)->postJson("/api/tutor/bookings/{$session->id}/reviews", ['rating' => 1, 'review' => 'Rude.'])->json('data.id');
        $reporter = $this->makeUser('R1');

        $this->actingAs($reporter)->postJson("/api/tutor/reviews/{$id}/report", ['reason' => 'Not true at all'])->assertCreated();
        $this->actingAs($reporter)->postJson("/api/tutor/reviews/{$id}/report", ['reason' => 'Not true at all'])->assertStatus(409);
        $this->actingAs($learner)->postJson("/api/tutor/reviews/{$id}/report", ['reason' => 'my own review'])->assertStatus(422);
        $this->assertSame('published', SessionRating::find($id)->status);

        $this->actingAs($this->makeUser('R2'))->postJson("/api/tutor/reviews/{$id}/report", ['reason' => 'Abusive words']);
        $this->actingAs($this->makeUser('R3'))->postJson("/api/tutor/reviews/{$id}/report", ['reason' => 'Abusive words']);
        $this->assertSame('held', SessionRating::find($id)->status);

        $operator = $this->makeOperator();
        $this->actingAs($operator)->getJson('/api/tutor/operator/reviews')->assertJsonPath('data.0.reports_count', 3);
        $this->actingAs($operator)->postJson("/api/tutor/operator/reviews/{$id}/moderate", ['decision' => 'publish', 'reason' => 'Honest, if harsh.'])
            ->assertOk()->assertJsonPath('data.status', 'published');
        $this->assertSame('1.00', $tutor->fresh()->rating);
    }

    public function test_the_tutor_replies_publicly_without_contact_details(): void
    {
        [$tutor, $learner, $session] = $this->completed();
        $id = $this->actingAs($learner)->postJson("/api/tutor/bookings/{$session->id}/reviews", ['rating' => 3])->json('data.id');

        $this->actingAs($learner)->postJson("/api/tutor/reviews/{$id}/reply", ['reply' => 'me too'])->assertForbidden();
        $this->actingAs($tutor->user)->postJson("/api/tutor/reviews/{$id}/reply", ['reply' => 'See www.example.com'])->assertStatus(422)->assertJsonPath('error', 'contact_details');
        $this->actingAs($tutor->user)->postJson("/api/tutor/reviews/{$id}/reply", ['reply' => 'Thanks, we will do more past papers.'])->assertOk();

        $this->actingAs($this->makeUser())->getJson("/api/tutor/tutors/{$tutor->id}/reviews")
            ->assertJsonPath('data.0.tutor_reply', 'Thanks, we will do more past papers.')
            ->assertJsonPath('data.0.by', 'Lerato')
            ->assertJsonPath('summary.distribution.3', 1);
    }

    public function test_api_errors_are_json_with_error_and_message(): void
    {
        $this->getJson('/api/tutor/tutors')->assertUnauthorized()->assertJsonPath('error', 'unauthenticated');
        $this->actingAs($this->makeUser())->getJson('/api/tutor/tutors/999999')->assertNotFound()->assertJsonStructure(['error', 'message']);
        $this->actingAs($this->makeUser())->getJson('/api/tutor/operator/qualifications')->assertForbidden()->assertJsonStructure(['error', 'message']);
        $this->actingAs($this->makeUser())->getJson('/api/tutor/me/profile')->assertNotFound()->assertJsonStructure(['error', 'message']);
    }

    public function test_discovery_lists_only_approved_tutors_in_a_fair_rotation(): void
    {
        $subject = $this->makeSubject();
        foreach (range(1, 4) as $i) {
            $this->makeTutor([], $subject, $this->makeUser("Tutor {$i}"));
        }
        $this->makeTutor(['status' => 'pending'], $subject, $this->makeUser('Pending'));

        $response = $this->actingAs($this->makeUser())->getJson("/api/tutor/tutors?subject_id={$subject->id}")->assertOk();

        $this->assertSame(4, $response->json('meta.total'));
        $this->assertSame('daily_rotation', $response->json('meta.order'));
        $this->assertNotContains('Pending', array_column($response->json('data'), 'name'));
    }

    public function test_the_storefront_has_what_a_learner_needs_to_decide(): void
    {
        [$tutor, $subject, $offering] = $this->bookableTutor(['type' => 'exam_prep', 'title' => 'Matric exam prep', 'price_minor' => 45000, 'duration_minutes' => 90]);

        $data = $this->actingAs($this->makeUser())->getJson("/api/tutor/tutors/{$tutor->id}")->assertOk()->json('data');

        foreach (['introduction', 'qualifications', 'teaching_scope', 'subjects', 'formats', 'pricing', 'offerings', 'reviews', 'availability_url', 'verification', 'share_url'] as $key) {
            $this->assertArrayHasKey($key, $data);
        }
        $this->assertSame('ZAR 450.00', $data['offerings'][0]['price_display']);
        $this->assertStringContainsString('24 hours', $data['offerings'][0]['cancellation']['summary']);
        $this->assertNull($data['courses'], 'Courses are not connected on this branch: null, not an empty list.');

        $slots = $this->actingAs($this->makeUser())->getJson("/api/tutor/tutors/{$tutor->id}/availability?duration=90")->assertOk()->json('days');
        $this->assertNotEmpty(collect($slots)->pluck('slots')->flatten());
    }

    public function test_offerings_are_validated_by_type_and_scope(): void
    {
        $subject = $this->makeSubject('Maths');
        $other = $this->makeSubject('History');
        $tutor = $this->makeTutor([], $subject);
        $post = fn (array $data) => $this->actingAs($tutor->user)->postJson('/api/tutor/me/offerings', array_merge([
            'type' => 'one_to_one', 'title' => 'Lesson', 'price_minor' => 30000, 'duration_minutes' => 60, 'format' => 'online', 'subject_ids' => [$subject->id],
        ], $data));

        $post([])->assertCreated()->assertJsonPath('data.capacity', 1)->assertJsonPath('data.currency', 'ZAR');
        $post(['type' => 'group', 'capacity' => 1])->assertStatus(422)->assertJsonValidationErrors('capacity');
        $post(['type' => 'package'])->assertStatus(422)->assertJsonValidationErrors('package_lessons');
        $post(['type' => 'recurring_weekly'])->assertStatus(422)->assertJsonValidationErrors('recurring_weeks');
        $post(['format' => 'in_person'])->assertStatus(422)->assertJsonValidationErrors('area');
        $post(['subject_ids' => [$other->id]])->assertStatus(422)->assertJsonValidationErrors('subject_ids');
        $post(['duration_minutes' => 50])->assertStatus(422);
        $post(['price_minor' => 12.5])->assertStatus(422);
        $post(['level_ids' => [11]])->assertStatus(422)->assertJsonValidationErrors('level_ids');

        $this->fakeAcademic();
        $post(['level_ids' => [11, 12]])->assertCreated();
        $id = $post(['type' => 'package', 'package_lessons' => 5, 'price_minor' => 120000])->assertCreated()->json('data.id');

        $this->actingAs($tutor->user)->patchJson("/api/tutor/me/offerings/{$id}", ['price_minor' => 110000])->assertOk()->assertJsonPath('data.price_minor', 110000);
        $this->actingAs($this->makeUser())->patchJson("/api/tutor/me/offerings/{$id}", ['price_minor' => 1])->assertNotFound();
        $this->actingAs($tutor->user)->postJson("/api/tutor/me/offerings/{$id}/archive")->assertJsonPath('data.is_active', false);
    }

    public function test_a_price_change_never_touches_an_existing_booking(): void
    {
        [$tutor, $subject, $offering] = $this->bookableTutor();
        $learner = $this->makeUser();
        $id = $this->actingAs($learner)->postJson("/api/tutor/offerings/{$offering->id}/bookings", [
            'subject_id' => $subject->id, 'starts_at' => $this->slotAt($tutor, 2)->toIso8601String(),
        ])->json('data.id');

        $this->actingAs($tutor->user)->patchJson("/api/tutor/me/offerings/{$offering->id}", ['price_minor' => 99900])->assertOk();

        $this->actingAs($learner)->getJson("/api/tutor/bookings/{$id}")->assertJsonPath('data.price.amount_minor', 30000);
    }

    public function test_teaching_scope_keeps_the_legacy_subject_list_in_step(): void
    {
        $maths = $this->makeSubject('Maths');
        $science = $this->makeSubject('Physical Sciences');
        $tutor = $this->makeTutor([], $maths);

        $this->actingAs($tutor->user)->putJson('/api/tutor/me/scopes', ['scopes' => [['subject_id' => $science->id, 'level_min_id' => 10]]])
            ->assertStatus(422);
        $this->actingAs($tutor->user)->putJson('/api/tutor/me/scopes', ['scopes' => [['subject_id' => $science->id], ['subject_id' => $maths->id]]])->assertOk();

        $this->assertEqualsCanonicalizing([$maths->id, $science->id], $tutor->subjects()->pluck('subjects.id')->all());
    }

    public function test_the_minimal_journey_pages_render(): void
    {
        [$tutor, $subject, $offering] = $this->bookableTutor();
        $learner = $this->makeUser();

        $this->actingAs($learner)->get('/find-a-tutor')->assertOk()->assertSee('Find a tutor');
        $this->actingAs($learner)->get("/find-a-tutor?subject_id={$subject->id}&format=online&budget_rand=400")->assertOk()->assertSee($tutor->user->name);
        $this->actingAs($learner)->get("/t/{$tutor->id}")->assertOk()->assertSee('Request this lesson')->assertSee('Not yet verified');

        $this->actingAs($learner)->post("/t/{$tutor->id}/offerings/{$offering->id}/book", [
            'subject_id' => $subject->id, 'starts_at' => $this->slotAt($tutor, 2)->format('Y-m-d H:i'),
        ])->assertRedirect('/my/bookings');
        $this->actingAs($learner)->get('/my/bookings')->assertOk()->assertSee('Waiting for the tutor to accept or decline.');

        $id = TutorSession::query()->value('id');
        $this->actingAs($tutor->user)->post("/my/bookings/{$id}/accept")->assertRedirect('/my/bookings');
        $this->assertSame('awaiting_payment', TutorSession::find($id)->status);

        $this->actingAs($this->makeOperator())->get('/operator/verification')->assertOk()->assertSee('Nothing waiting for review.');
        $this->actingAs($learner)->get('/operator/verification')->assertForbidden();
    }
}
