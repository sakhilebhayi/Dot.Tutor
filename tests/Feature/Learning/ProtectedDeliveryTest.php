<?php

namespace Tests\Feature\Learning;

use App\Domain\Learning\Contracts\Seams\EntitlementLookup;
use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\CourseMaterial;
use App\Domain\Learning\Services\CoursePublisher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ProtectedDeliveryTest extends TestCase
{
    use LearningFixtures;
    use RefreshDatabase;

    /** @var array<string, bool> type:id => owned */
    private array $owned = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('learning');
        $owned = &$this->owned;
        $this->app->instance(EntitlementLookup::class, new class($owned) implements EntitlementLookup
        {
            public function __construct(private array &$owned) {}

            public function owns(User $user, string $type, int $id): bool
            {
                return $this->owned["{$user->id}:{$type}:{$id}"] ?? false;
            }
        });
    }

    private function paidCourse(User $tutor): Course
    {
        return $this->publishedCourse($tutor, ['pricing' => 'paid', 'price_minor' => 19900, 'refund_policy' => 'Refund within 7 days.']);
    }

    private function grant(User $user, string $type, int $id, bool $owned = true): void
    {
        $this->owned["{$user->id}:{$type}:{$id}"] = $owned;
    }

    public function test_a_non_buyer_cannot_read_paid_lessons_by_guessing_ids(): void
    {
        $course = $this->paidCourse($this->approvedTutor());
        [$preview, $paid] = $this->lessonIds($course);
        $stranger = $this->learner();
        $this->actingAs($stranger);

        $this->getJson("/api/tutor/learning/courses/{$course->id}")->assertOk()
            ->assertJsonPath('data.price_minor', 19900)
            ->assertJsonPath('data.refund_policy', 'Refund within 7 days.')
            ->assertJsonMissing(['body' => 'Secret paid content: x^2 + 5x + 6 = (x + 2)(x + 3).']);
        $this->getJson("/api/tutor/learning/courses/{$course->id}/learn/{$paid}")->assertForbidden();
        $this->get("/learning/courses/{$course->id}/learn/{$paid}")->assertForbidden();
        $this->getJson("/api/tutor/learning/courses/{$course->id}/learn/{$preview}")->assertOk()->assertJsonPath('data.preview_only', true);
        foreach ([$paid + 1, $paid + 50, 999999] as $guess) {
            $this->getJson("/api/tutor/learning/courses/{$course->id}/learn/{$guess}")->assertForbidden();
        }
        $this->postJson("/api/tutor/learning/courses/{$course->id}/enrol")->assertUnprocessable();
        $this->postJson("/api/tutor/learning/courses/{$course->id}/lessons/{$paid}/complete")->assertForbidden();
    }

    public function test_a_lesson_id_from_another_course_is_not_readable_through_this_course(): void
    {
        $tutor = $this->approvedTutor();
        $free = $this->publishedCourse($tutor);
        $paid = $this->paidCourse($tutor);
        $learner = $this->learner();
        $this->actingAs($learner)->postJson("/api/tutor/learning/courses/{$free->id}/enrol")->assertCreated();

        $paidLesson = $this->lessonIds($paid)[1];
        $this->getJson("/api/tutor/learning/courses/{$free->id}/learn/{$paidLesson}")->assertForbidden();
    }

    public function test_an_entitled_buyer_enrols_and_loses_access_when_the_entitlement_ends(): void
    {
        $course = $this->paidCourse($this->approvedTutor());
        $paid = $this->lessonIds($course)[1];
        $buyer = $this->learner();
        $this->grant($buyer, 'course', $course->id);
        $this->actingAs($buyer);

        $this->postJson("/api/tutor/learning/courses/{$course->id}/enrol")->assertCreated();
        $this->getJson("/api/tutor/learning/courses/{$course->id}/learn/{$paid}")->assertOk()
            ->assertJsonPath('data.lesson.title', 'Factorising')
            ->assertJsonPath('data.preview_only', false);

        // A refund in the money domain revokes the entitlement: access ends on the next request.
        $this->grant($buyer, 'course', $course->id, false);
        $this->getJson("/api/tutor/learning/courses/{$course->id}/learn/{$paid}")->assertForbidden();
    }

    public function test_a_bundle_entitlement_opens_every_course_in_it(): void
    {
        $tutor = $this->approvedTutor();
        $a = $this->paidCourse($tutor);
        $b = $this->paidCourse($tutor);
        $bundleId = $this->actingAs($tutor)->postJson('/api/tutor/learning/studio/bundles', ['title' => 'Pack', 'price_minor' => 30000, 'course_ids' => [$a->id, $b->id]])->json('data.id');
        $buyer = $this->learner();
        $this->grant($buyer, 'bundle', $bundleId);

        $this->actingAs($buyer)->postJson("/api/tutor/learning/courses/{$b->id}/enrol")->assertCreated();
    }

    public function test_media_is_served_only_through_a_signed_url_bound_to_the_viewer(): void
    {
        $tutor = $this->approvedTutor();
        $course = $this->draftCourse($tutor);
        $this->actingAs($tutor)->post("/api/tutor/learning/studio/sections/{$course->sections()->first()->id}/lessons", [
            'title' => 'Video lesson', 'type' => 'video', 'file' => $this->mp4Upload(bytes: 20000),
        ], ['Accept' => 'application/json'])->assertCreated();
        app(CoursePublisher::class)->publish($course->fresh(), $tutor);
        $course->refresh();
        $videoId = $course->lessons()->where('title', 'Video lesson')->value('id');

        $learner = $this->learner();
        $this->actingAs($learner)->postJson("/api/tutor/learning/courses/{$course->id}/enrol")->assertCreated();
        $mediaUrl = $this->getJson("/api/tutor/learning/courses/{$course->id}/learn/{$videoId}")->assertOk()->json('data.lesson.media_url');
        $this->assertStringContainsString('signature=', $mediaUrl);
        $this->assertStringNotContainsString('storage', $mediaUrl);

        $full = $this->get($mediaUrl);
        $full->assertOk();
        $this->assertSame('video/mp4', $full->headers->get('Content-Type'));
        $this->assertSame('nosniff', $full->headers->get('X-Content-Type-Options'));
        $this->assertStringStartsWith('inline', $full->headers->get('Content-Disposition'));

        $range = $this->get($mediaUrl, ['Range' => 'bytes=0-99']);
        $range->assertStatus(206);
        $this->assertSame('bytes 0-99/20000', $range->headers->get('Content-Range'));

        // Tampering with the signature, or replaying another user's URL, fails.
        $this->get(str_replace('signature=', 'signature=x', $mediaUrl))->assertForbidden();
        $this->actingAs($this->learner('Someone Else'))->get($mediaUrl)->assertForbidden();

        // An unsigned guess at the media route fails.
        $version = $course->current_version_id;
        $this->actingAs($learner)->get("/learning/media/{$course->id}/lesson/{$videoId}?v={$version}&u={$learner->id}")->assertForbidden();

        // An expired link fails.
        $this->travel(11)->minutes();
        $this->get($mediaUrl)->assertForbidden();
    }

    public function test_a_signed_url_does_not_outlive_access(): void
    {
        $tutor = $this->approvedTutor();
        $course = $this->draftCourse($tutor, ['pricing' => 'paid', 'price_minor' => 5000, 'refund_policy' => 'Refund in 7 days.']);
        $this->actingAs($tutor)->post("/api/tutor/learning/studio/sections/{$course->sections()->first()->id}/lessons", [
            'title' => 'Video lesson', 'type' => 'video', 'file' => $this->mp4Upload(),
        ], ['Accept' => 'application/json'])->assertCreated();
        app(CoursePublisher::class)->publish($course->fresh(), $tutor);
        $course->refresh();
        $videoId = $course->lessons()->where('title', 'Video lesson')->value('id');
        $buyer = $this->learner();
        $this->grant($buyer, 'course', $course->id);
        $this->actingAs($buyer)->postJson("/api/tutor/learning/courses/{$course->id}/enrol")->assertCreated();
        $url = $this->getJson("/api/tutor/learning/courses/{$course->id}/learn/{$videoId}")->json('data.lesson.media_url');

        $this->grant($buyer, 'course', $course->id, false);
        $this->get($url)->assertForbidden();
    }

    public function test_materials_follow_their_per_file_permission(): void
    {
        $tutor = $this->approvedTutor();
        $course = $this->draftCourse($tutor);
        $this->actingAs($tutor);
        foreach (['enrolled_download' => 'Worksheet', 'enrolled_view_only' => 'Memo', 'public_preview' => 'Syllabus'] as $permission => $title) {
            $this->post("/api/tutor/learning/studio/courses/{$course->id}/materials", ['title' => $title, 'permission' => $permission, 'file' => $this->pdfUpload()], ['Accept' => 'application/json'])->assertCreated();
        }
        app(CoursePublisher::class)->publish($course->fresh(), $tutor);
        $ids = CourseMaterial::query()->orderBy('id')->pluck('id', 'title');

        $stranger = $this->learner('Stranger');
        $this->actingAs($stranger);
        $this->get("/api/tutor/learning/courses/{$course->id}/materials/{$ids['Worksheet']}")->assertForbidden();
        $this->get("/api/tutor/learning/courses/{$course->id}/materials/{$ids['Memo']}")->assertForbidden();
        $this->get("/api/tutor/learning/courses/{$course->id}/materials/{$ids['Syllabus']}")->assertOk();

        $learner = $this->learner();
        $this->actingAs($learner)->postJson("/api/tutor/learning/courses/{$course->id}/enrol")->assertCreated();
        $download = $this->get("/api/tutor/learning/courses/{$course->id}/materials/{$ids['Worksheet']}")->assertOk();
        $this->assertStringStartsWith('attachment', $download->headers->get('Content-Disposition'));
        $view = $this->get("/api/tutor/learning/courses/{$course->id}/materials/{$ids['Memo']}")->assertOk();
        $this->assertStringStartsWith('inline', $view->headers->get('Content-Disposition'));
    }

    public function test_paid_checkout_says_honestly_that_payments_are_off_and_coupons_are_not_applied(): void
    {
        $course = $this->paidCourse($this->approvedTutor());
        $this->actingAs($this->learner());

        $this->postJson("/api/tutor/learning/courses/{$course->id}/checkout")->assertStatus(400)->assertJsonPath('error', 'idempotency_key_required');
        $this->postJson("/api/tutor/learning/courses/{$course->id}/checkout", [], ['Idempotency-Key' => 'k-1'])
            ->assertStatus(503)->assertJsonPath('error', 'payments_unavailable');
        $this->getJson("/api/tutor/learning/courses/{$course->id}/quote?coupon=SAVE10")->assertOk()
            ->assertJsonPath('data.payable_minor', 19900)
            ->assertJsonPath('data.coupon_accepted', false)
            ->assertJsonPath('data.message', 'Coupons are not available yet.');
    }

    public function test_storage_paths_never_reach_the_client(): void
    {
        $tutor = $this->approvedTutor();
        $course = $this->draftCourse($tutor);
        $this->actingAs($tutor)->post("/api/tutor/learning/studio/courses/{$course->id}/materials", ['title' => 'Sheet', 'permission' => 'public_preview', 'file' => $this->pdfUpload()], ['Accept' => 'application/json'])->assertCreated();
        app(CoursePublisher::class)->publish($course->fresh(), $tutor);
        $path = CourseMaterial::query()->value('path');

        $this->actingAs($this->learner());
        foreach (["/api/tutor/learning/courses/{$course->id}", "/api/tutor/learning/courses/{$course->id}/learn/{$this->lessonIds($course)[0]}"] as $url) {
            $this->assertStringNotContainsString($path, $this->getJson($url)->assertOk()->getContent());
        }
        $this->actingAs($tutor);
        $this->assertStringNotContainsString($path, $this->getJson("/api/tutor/learning/studio/courses/{$course->id}")->getContent());
    }

    public function test_the_creator_previews_draft_media_through_a_draft_signed_url(): void
    {
        $tutor = $this->approvedTutor();
        $course = $this->draftCourse($tutor);
        $this->actingAs($tutor)->post("/api/tutor/learning/studio/sections/{$course->sections()->first()->id}/lessons", [
            'title' => 'Draft video', 'type' => 'video', 'file' => $this->mp4Upload(),
        ], ['Accept' => 'application/json'])->assertCreated();
        $lessonId = $course->lessons()->where('title', 'Draft video')->value('id');

        $url = $this->getJson("/api/tutor/learning/studio/lessons/{$lessonId}/preview")->assertOk()->json('data.lesson.media_url');
        $this->get($url)->assertOk();

        $other = $this->learner();
        $this->actingAs($other)->getJson("/api/tutor/learning/studio/lessons/{$lessonId}/preview")->assertForbidden();
        $forged = URL::temporarySignedRoute('learning.media', now()->addMinutes(5), ['course' => $course->id, 'kind' => 'lesson', 'item' => $lessonId, 'v' => 'draft', 'u' => $other->id]);
        $this->get($forged)->assertForbidden();
    }
}
