<?php

namespace Tests\Feature\Learning;

use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\Lesson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CourseAuthoringTest extends TestCase
{
    use LearningFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('learning');
    }

    public function test_a_creator_builds_and_publishes_a_course_through_the_api(): void
    {
        $tutor = $this->approvedTutor();
        $subject = $this->subject();

        $id = $this->actingAs($tutor)->postJson('/api/tutor/learning/studio/courses', [
            'title' => 'Physical Sciences: Newton\'s laws',
            'subject_id' => $subject->id,
            'original_locale' => 'zu',
        ])->assertCreated()->json('data.id');

        // Empty course: cannot publish, and every reason is spelled out.
        $problems = $this->postJson("/api/tutor/learning/studio/courses/{$id}/publish")->assertUnprocessable()->json('errors.course');
        $this->assertContains('Add at least one section with a lesson. A course cannot publish empty.', $problems);
        $this->assertContains('Choose whether the course is free or paid.', $problems);

        $this->putJson("/api/tutor/learning/studio/courses/{$id}", [
            'description' => 'Forces, motion and momentum for Grade 11, taught in isiZulu with English terms.',
            'objectives' => ['State Newton\'s three laws', '', 'Apply F = ma'],
            'prerequisites' => ['Grade 10 vectors'],
            'workload_minutes' => 240,
            'pricing' => 'paid',
            'price_minor' => 24900,
        ])->assertOk()->assertJsonPath('data.objectives', ['State Newton\'s three laws', 'Apply F = ma']);

        $sectionId = $this->postJson("/api/tutor/learning/studio/courses/{$id}/sections", ['title' => 'Forces'])->assertCreated()->json('data.id');
        $this->postJson("/api/tutor/learning/studio/sections/{$sectionId}/lessons", ['title' => 'What is a force?', 'type' => 'text', 'body' => 'A push or a pull.'])->assertCreated();

        $problems = $this->postJson("/api/tutor/learning/studio/courses/{$id}/publish")->assertUnprocessable()->json('errors.course');
        $this->assertSame(['Write the refund policy learners see before they buy.'], $problems);

        $this->putJson("/api/tutor/learning/studio/courses/{$id}", ['refund_policy' => 'Full refund within 7 days if you have completed under 20% of lessons.'])->assertOk();
        $this->postJson("/api/tutor/learning/studio/courses/{$id}/publish")->assertCreated()->assertJsonPath('data.version', 1);

        $course = Course::findOrFail($id);
        $this->assertSame('published', $course->status->value);
        $this->getJson('/api/tutor/learning/courses')->assertOk()->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.price_minor', 24900)
            ->assertJsonPath('data.0.language', 'zu')
            ->assertJsonPath('data.0.refund_policy', 'Full refund within 7 days if you have completed under 20% of lessons.')
            ->assertJsonPath('data.0.outline.0.lessons.0.title', 'What is a force?');
    }

    public function test_only_an_approved_tutor_can_publish(): void
    {
        $notTutor = $this->learner('Not Yet Tutor');
        $course = $this->draftCourse($notTutor);

        $problems = $this->actingAs($notTutor)->postJson("/api/tutor/learning/studio/courses/{$course->id}/publish")
            ->assertUnprocessable()->json('errors.course');
        $this->assertContains('Only an approved tutor can publish a course. Finish your tutor profile and verification first.', $problems);
    }

    public function test_another_user_cannot_see_or_edit_a_draft(): void
    {
        $course = $this->draftCourse($this->approvedTutor());
        $other = $this->learner();

        $this->actingAs($other)->getJson("/api/tutor/learning/studio/courses/{$course->id}")->assertForbidden();
        $this->putJson("/api/tutor/learning/studio/courses/{$course->id}", ['title' => 'Hijacked'])->assertForbidden();
        $this->getJson("/api/tutor/learning/courses/{$course->id}")->assertNotFound();
        $this->getJson("/api/tutor/learning/courses/{$course->id}/learn/{$this->lessonIds($course)[0]}")->assertNotFound();
    }

    public function test_external_video_links_must_be_https_on_the_allow_list(): void
    {
        $tutor = $this->approvedTutor();
        $course = $this->draftCourse($tutor);
        $section = $course->sections()->first();
        $this->actingAs($tutor);

        $this->postJson("/api/tutor/learning/studio/sections/{$section->id}/lessons", ['title' => 'Clip', 'type' => 'video_url', 'external_url' => 'https://evil.example.com/v.mp4'])
            ->assertUnprocessable()->assertJsonValidationErrors('external_url');
        $this->postJson("/api/tutor/learning/studio/sections/{$section->id}/lessons", ['title' => 'Clip', 'type' => 'video_url', 'external_url' => 'http://www.youtube.com/watch?v=abc'])
            ->assertUnprocessable();
        $this->postJson("/api/tutor/learning/studio/sections/{$section->id}/lessons", ['title' => 'Clip', 'type' => 'video_url', 'external_url' => 'https://www.youtube.com/watch?v=abc'])
            ->assertCreated();
    }

    public function test_uploads_are_sniffed_by_content_not_by_name(): void
    {
        $tutor = $this->approvedTutor();
        $course = $this->draftCourse($tutor);
        $section = $course->sections()->first();
        $this->actingAs($tutor);

        $fakePdf = UploadedFile::fake()->createWithContent('notes.pdf', "MZ\x90\x00 this is really an executable");
        $this->post("/api/tutor/learning/studio/sections/{$section->id}/lessons", ['title' => 'Notes', 'type' => 'document', 'file' => $fakePdf], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->post("/api/tutor/learning/studio/sections/{$section->id}/lessons", ['title' => 'Macro doc', 'type' => 'document', 'file' => $this->docxUpload(withMacros: true)], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonPath('errors.file.0', 'Documents with macros are not accepted. Save it as a normal document first.');

        $this->post("/api/tutor/learning/studio/sections/{$section->id}/lessons", ['title' => 'Worksheet', 'type' => 'document', 'file' => $this->docxUpload()], ['Accept' => 'application/json'])
            ->assertCreated();
        $this->post("/api/tutor/learning/studio/sections/{$section->id}/lessons", ['title' => 'Video', 'type' => 'video', 'file' => $this->mp4Upload()], ['Accept' => 'application/json'])
            ->assertCreated();

        $image = UploadedFile::fake()->image('diagram.jpg', 120, 80);
        $original = file_get_contents($image->getRealPath());
        $this->post("/api/tutor/learning/studio/sections/{$section->id}/lessons", ['title' => 'Diagram', 'type' => 'image', 'file' => $image], ['Accept' => 'application/json'])
            ->assertCreated();

        $lesson = Lesson::query()->where('title', 'Diagram')->firstOrFail();
        $this->assertSame('image/jpeg', $lesson->media_mime);
        $this->assertNotSame($original, Storage::disk('learning')->get($lesson->media_path), 'Images are re-encoded, not stored as uploaded.');
        $this->assertSame('application/vnd.openxmlformats-officedocument.wordprocessingml.document', Lesson::query()->where('title', 'Worksheet')->value('media_mime'));
        $this->assertSame('video/mp4', Lesson::query()->where('title', 'Video')->value('media_mime'));
    }

    public function test_a_file_type_that_does_not_fit_the_lesson_is_refused(): void
    {
        $tutor = $this->approvedTutor();
        $section = $this->draftCourse($tutor)->sections()->first();

        $this->actingAs($tutor)->post("/api/tutor/learning/studio/sections/{$section->id}/lessons", ['title' => 'Video', 'type' => 'video', 'file' => $this->pdfUpload()], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_a_published_course_needs_its_uploaded_files_present(): void
    {
        $tutor = $this->approvedTutor();
        $course = $this->draftCourse($tutor);
        $course->sections()->first()->lessons()->create(['course_id' => $course->id, 'title' => 'Slides', 'type' => 'slides', 'position' => 3]);

        $problems = $this->actingAs($tutor)->postJson("/api/tutor/learning/studio/courses/{$course->id}/publish")->assertUnprocessable()->json('errors.course');
        $this->assertContains('Lesson "Slides": upload the file.', $problems);
    }

    public function test_the_academic_seam_refuses_curriculum_ids_until_the_academic_domain_exists(): void
    {
        $tutor = $this->approvedTutor();
        $course = $this->draftCourse($tutor);

        $this->actingAs($tutor)->putJson("/api/tutor/learning/studio/courses/{$course->id}", ['curriculum_id' => 99])
            ->assertUnprocessable()->assertJsonValidationErrors('curriculum_id');
        $this->putJson("/api/tutor/learning/studio/courses/{$course->id}", ['subject_id' => 999999])
            ->assertUnprocessable()->assertJsonValidationErrors('subject_id');
    }

    public function test_a_bundle_holds_only_the_creators_own_published_courses(): void
    {
        $tutor = $this->approvedTutor();
        $a = $this->publishedCourse($tutor);
        $b = $this->publishedCourse($tutor, ['pricing' => 'paid', 'price_minor' => 10000, 'refund_policy' => 'Refunds within 7 days.']);
        $someoneElses = $this->publishedCourse($this->approvedTutor('Other Tutor'));
        $this->actingAs($tutor);

        $this->postJson('/api/tutor/learning/studio/bundles', ['title' => 'Bundle', 'price_minor' => 15000, 'course_ids' => [$a->id, $someoneElses->id]])
            ->assertUnprocessable();
        $this->postJson('/api/tutor/learning/studio/bundles', ['title' => 'Algebra pack', 'price_minor' => 15000, 'course_ids' => [$a->id, $b->id]])
            ->assertCreated();
    }

    public function test_the_creator_sees_learner_progress(): void
    {
        $tutor = $this->approvedTutor();
        $course = $this->publishedCourse($tutor);
        $learner = $this->learner('Ayanda Learner');
        $this->actingAs($learner)->postJson("/api/tutor/learning/courses/{$course->id}/enrol")->assertCreated();
        $this->postJson("/api/tutor/learning/courses/{$course->id}/lessons/{$this->lessonIds($course)[0]}/complete")->assertOk();

        $this->actingAs($tutor)->getJson("/api/tutor/learning/studio/courses/{$course->id}/learners")
            ->assertOk()->assertJsonPath('data.0.learner', 'Ayanda Learner')->assertJsonPath('data.0.percent', 50);
        $this->actingAs($learner)->getJson("/api/tutor/learning/studio/courses/{$course->id}/learners")->assertForbidden();
    }
}
