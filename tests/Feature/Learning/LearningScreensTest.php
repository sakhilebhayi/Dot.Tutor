<?php

namespace Tests\Feature\Learning;

use App\Domain\Learning\Models\Attempt;
use App\Domain\Learning\Models\Course;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** The minimal wave-1 screens render and their forms drive the real flows. */
class LearningScreensTest extends TestCase
{
    use LearningFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('learning');
    }

    public function test_the_creator_journey_through_the_web_forms(): void
    {
        $tutor = $this->approvedTutor();
        $subject = $this->subject();
        $this->actingAs($tutor);

        $this->get('/learning/studio/courses')->assertOk()->assertSee('Start a course')->assertSee('No courses yet');
        $this->post('/learning/studio/courses', ['title' => 'Life Sciences: cells', 'subject_id' => $subject->id, 'original_locale' => 'en'])->assertRedirect();
        $course = Course::query()->firstOrFail();

        $this->get("/learning/studio/courses/{$course->id}")->assertOk()
            ->assertSee('Before this course can publish')
            ->assertSee('Choose whether the course is free or paid.');
        $this->post("/learning/studio/courses/{$course->id}/sections", ['title' => 'The cell'])->assertRedirect();
        $section = $course->sections()->first();
        $this->post("/learning/studio/sections/{$section->id}/lessons", ['title' => 'Organelles', 'type' => 'text', 'body' => 'The **nucleus** holds DNA.', 'is_preview' => '1'])->assertRedirect();
        $this->put("/learning/studio/courses/{$course->id}", [
            'title' => 'Life Sciences: cells',
            'description' => 'Cell structure and function for Grade 10, with diagrams and short quizzes.',
            'subject_id' => $subject->id,
            'original_locale' => 'en',
            'objectives' => ['Name the organelles', '', ''],
            'prerequisites' => ['', ''],
            'workload_minutes' => '90',
            'pricing' => 'free',
            'price_minor' => '',
            'refund_policy' => '',
            'completion_rule' => 'all_lessons',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->get("/learning/studio/courses/{$course->id}")->assertSee('Ready.');
        $this->post("/learning/studio/courses/{$course->id}/publish")->assertRedirect()->assertSessionHas('status', 'Published as version 1.');
        $this->get("/learning/studio/lessons/{$course->lessons()->first()->id}/preview")->assertOk()->assertSee('Draft preview');
    }

    public function test_the_learner_journey_through_the_web_screens(): void
    {
        $course = $this->publishedCourse($this->approvedTutor(), ['completion_rule' => 'all_lessons']);
        [$first, $second] = $this->lessonIds($course);
        $this->actingAs($this->learner());

        $this->get('/learning/courses')->assertOk()->assertSee('Grade 10 Algebra foundations');
        $this->get("/learning/courses/{$course->id}")->assertOk()->assertSee('You will learn to')->assertSee('Enrol')->assertSee('Free preview');
        $this->get("/learning/courses/{$course->id}/learn/{$first}")->assertOk()->assertSee('<strong>algebra</strong>', false);
        $this->post("/learning/courses/{$course->id}/enrol")->assertRedirect(route('learning.learn', $course));
        $this->get("/learning/courses/{$course->id}/learn")->assertOk()->assertSee('Mark as complete')->assertSee('0 of 2 lessons complete');
        $this->post("/learning/courses/{$course->id}/lessons/{$first}/complete")->assertRedirect(route('learning.learn', [$course, $second]));
        $this->post("/learning/courses/{$course->id}/lessons/{$second}/complete")->assertRedirect();
        $this->get("/learning/courses/{$course->id}")->assertSee('2 of 2 lessons complete');
    }

    public function test_paid_course_page_shows_price_and_refund_policy_before_purchase(): void
    {
        $course = $this->publishedCourse($this->approvedTutor(), ['pricing' => 'paid', 'price_minor' => 24900, 'refund_policy' => 'Refunds within 7 days if under 20% complete.']);
        $this->actingAs($this->learner());

        $this->get("/learning/courses/{$course->id}")->assertOk()->assertSee("R249.00") /* R-47 student UI (D-1118): money in the ds format */->assertSee('Refunds within 7 days if under 20% complete.')->assertSee('Buy course');
        $this->post("/learning/courses/{$course->id}/checkout", ['idempotency_key' => 'abc'])->assertRedirect()
            // Money says so itself now that checkout is bound to it (D-1400); no Paystack key in tests.
            ->assertSessionHasErrors(['checkout' => 'Online payment is not enabled on this environment. Paid items cannot be checked out.']);
    }

    public function test_library_screens_and_quiz_screens_render(): void
    {
        $tutor = $this->approvedTutor();
        $this->actingAs($tutor);
        $this->get('/learning/library')->assertOk()->assertSee('Share material')->assertSee('Nothing matches yet');
        $this->post('/learning/library', [
            'title' => 'Cell diagram', 'type' => 'diagram', 'ownership' => 'own_work', 'licence' => 'cc_by', 'is_free' => '1',
            'rights_confirmed' => '1', 'file' => $this->pdfUpload(),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/learning/library')->assertSee('Cell diagram')->assertSee('Tutor created');

        $bank = $this->postJson('/api/tutor/learning/banks', ['title' => 'Cells', 'topic' => 'Cell structure'])->json('data.id');
        $q = $this->postJson("/api/tutor/learning/banks/{$bank}/questions", ['type' => 'short_text', 'prompt' => 'Where is DNA stored?', 'answer' => ['accepted' => ['nucleus']], 'explanation' => 'In the nucleus.'])->json('data.id');
        $assessment = $this->postJson('/api/tutor/learning/assessments', ['title' => 'Cells check', 'kind' => 'practice'])->json('data.id');
        $this->postJson("/api/tutor/learning/assessments/{$assessment}/questions", ['question_ids' => [$q]]);

        $this->get("/learning/assessments/{$assessment}/take")->assertOk()->assertSee('Start');
        $this->post("/learning/assessments/{$assessment}/attempts")->assertRedirect("/learning/assessments/{$assessment}/take");
        $page = $this->get("/learning/assessments/{$assessment}/take")->assertOk()->assertSee('Where is DNA stored?');
        $this->assertStringNotContainsString('In the nucleus.', $page->getContent());
        $attempt = Attempt::query()->firstOrFail();
        $this->post("/learning/attempts/{$attempt->id}/submit", ['answers' => [$q => 'Nucleus']])->assertRedirect("/learning/attempts/{$attempt->id}");
        $this->get("/learning/attempts/{$attempt->id}")->assertOk()->assertSee('1 out of 1')->assertSee('In the nucleus.')->assertSee('Practice result');
    }
}
