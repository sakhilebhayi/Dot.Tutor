<?php

namespace Tests\Feature\Learning;

use App\Domain\Learning\Contracts\ProgressFeed;
use App\Domain\Learning\Models\Certificate;
use App\Domain\Learning\Models\Lesson;
use App\Domain\Learning\Services\CoursePublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProgressAndCertificatesTest extends TestCase
{
    use LearningFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('learning');
    }

    public function test_progress_resume_point_and_a_certificate_when_the_course_defines_completion(): void
    {
        $tutor = $this->approvedTutor('Thandi Mokoena');
        $course = $this->publishedCourse($tutor, ['completion_rule' => 'all_lessons']);
        [$first, $second] = $this->lessonIds($course);
        $learner = $this->learner('Sipho Bongani Dlamini');
        $this->actingAs($learner)->postJson("/api/tutor/learning/courses/{$course->id}/enrol")->assertCreated()
            ->assertJsonPath('data.progress.resume_lesson_id', $first)
            ->assertJsonPath('data.progress.percent', 0);

        $this->postJson("/api/tutor/learning/courses/{$course->id}/lessons/{$second}/position", ['seconds' => 95])->assertOk();
        $this->getJson("/api/tutor/learning/courses/{$course->id}/progress")->assertJsonPath('data.resume_lesson_id', $second)
            ->assertJsonPath('data.resume_position_seconds', 95);

        $this->postJson("/api/tutor/learning/courses/{$course->id}/lessons/{$first}/complete")->assertOk()->assertJsonPath('data.percent', 50)
            ->assertJsonPath('data.certificate', null);
        $code = $this->postJson("/api/tutor/learning/courses/{$course->id}/lessons/{$second}/complete")->assertOk()
            ->assertJsonPath('data.percent', 100)->json('data.certificate');
        $this->assertMatchesRegularExpression('/^[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}$/', $code);

        // Completing again does not issue a second certificate.
        $this->postJson("/api/tutor/learning/courses/{$course->id}/lessons/{$second}/complete")->assertOk();
        $this->assertSame(1, Certificate::query()->count());

        // Public verification works without signing in and shows initials by default.
        $this->app["auth"]->forgetGuards();
        $this->getJson("/certificates/{$code}")->assertOk()
            ->assertJsonPath('data.valid', true)
            ->assertJsonPath('data.learner', 'S. B. D.')
            ->assertJsonPath('data.course_title', 'Grade 10 Algebra foundations')
            ->assertJsonPath('data.tutor_name', 'Thandi Mokoena');
        $this->get("/certificates/{$code}")->assertOk()->assertSee('Grade 10 Algebra foundations');
        $this->getJson('/certificates/AAAA-BBBB-CCCC')->assertNotFound();

        // The holder can choose to show the full name.
        $certificate = Certificate::query()->firstOrFail();
        $this->actingAs($learner)->postJson("/api/tutor/learning/certificates/{$certificate->id}/name", ['show_full_name' => true])->assertOk();
        $this->getJson("/certificates/{$code}")->assertJsonPath('data.learner', 'Sipho Bongani Dlamini');
        $this->actingAs($this->learner('Other'))->postJson("/api/tutor/learning/certificates/{$certificate->id}/name", ['show_full_name' => false])->assertNotFound();
    }

    public function test_no_certificate_without_a_completion_rule(): void
    {
        $course = $this->publishedCourse($this->approvedTutor());
        $this->actingAs($this->learner())->postJson("/api/tutor/learning/courses/{$course->id}/enrol")->assertCreated();
        foreach ($this->lessonIds($course) as $id) {
            $this->postJson("/api/tutor/learning/courses/{$course->id}/lessons/{$id}/complete")->assertOk();
        }

        $this->getJson("/api/tutor/learning/courses/{$course->id}/progress")->assertJsonPath('data.percent', 100)->assertJsonPath('data.certificate', null);
        $this->assertSame(0, Certificate::query()->count());
    }

    public function test_edits_after_publishing_do_not_change_an_enrolled_learners_course_until_they_move(): void
    {
        $tutor = $this->approvedTutor();
        $course = $this->publishedCourse($tutor);
        [$first, $second] = $this->lessonIds($course);
        $learner = $this->learner();
        $this->actingAs($learner)->postJson("/api/tutor/learning/courses/{$course->id}/enrol")->assertCreated();
        $this->postJson("/api/tutor/learning/courses/{$course->id}/lessons/{$first}/complete")->assertOk();

        // The creator rewrites a lesson, deletes another and adds a new one, then publishes version 2.
        $this->actingAs($tutor)->postJson("/api/tutor/learning/studio/lessons/{$first}", ['body' => 'Rewritten welcome.'])->assertOk();
        $this->deleteJson("/api/tutor/learning/studio/lessons/{$second}")->assertNoContent();
        $this->postJson("/api/tutor/learning/studio/sections/{$course->sections()->first()->id}/lessons", ['title' => 'Inequalities', 'type' => 'text', 'body' => 'New material.'])->assertCreated();
        $this->getJson("/api/tutor/learning/studio/courses/{$course->id}")->assertJsonPath('data.has_unpublished_changes', true);
        $this->postJson("/api/tutor/learning/studio/courses/{$course->id}/publish")->assertCreated()->assertJsonPath('data.version', 2);

        // The learner still reads version 1, including the lesson the creator deleted.
        $this->actingAs($learner);
        $this->getJson("/api/tutor/learning/courses/{$course->id}/learn/{$first}")->assertOk()
            ->assertJsonPath('data.course.version', 1)
            ->assertJsonPath('data.newer_version_available', true)
            ->assertSee('Welcome to', false);
        $this->getJson("/api/tutor/learning/courses/{$course->id}/learn/{$second}")->assertOk()->assertJsonPath('data.lesson.title', 'Factorising');

        // Moving to the newest version keeps the completion of the lesson that still exists.
        $this->postJson("/api/tutor/learning/courses/{$course->id}/upgrade")->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.completed', 1)
            ->assertJsonPath('data.total', 2);
        $this->getJson("/api/tutor/learning/courses/{$course->id}/learn/{$first}")->assertSee('Rewritten welcome.', false);
        $this->getJson("/api/tutor/learning/courses/{$course->id}/learn/{$second}")->assertForbidden();
    }

    public function test_a_quiz_lesson_completes_only_through_a_submitted_attempt_and_can_gate_the_certificate(): void
    {
        $tutor = $this->approvedTutor();
        $course = $this->draftCourse($tutor, ['completion_rule' => 'all_lessons_and_quizzes']);
        $this->actingAs($tutor);
        $bankId = $this->postJson('/api/tutor/learning/banks', ['title' => 'Factorising', 'topic' => 'Factorising', 'strand' => 'Algebra', 'subject_id' => $this->subject()->id])->json('data.id');
        $questionId = $this->postJson("/api/tutor/learning/banks/{$bankId}/questions", ['type' => 'numeric', 'prompt' => 'Solve x + 2 = 5', 'answer' => ['value' => 3, 'tolerance' => 0]])->json('data.id');
        $quizId = $this->postJson('/api/tutor/learning/assessments', ['title' => 'Check-in', 'kind' => 'course_quiz', 'course_id' => $course->id, 'pass_percent' => 100])->json('data.id');
        $this->postJson("/api/tutor/learning/assessments/{$quizId}/questions", ['question_ids' => [$questionId]])->assertOk();
        $this->postJson("/api/tutor/learning/studio/sections/{$course->sections()->first()->id}/lessons", ['title' => 'Check-in quiz', 'type' => 'quiz', 'assessment_id' => $quizId])->assertCreated();
        $this->postJson("/api/tutor/learning/studio/courses/{$course->id}/publish")->assertCreated();
        $quizLesson = Lesson::query()->where('type', 'quiz')->value('id');

        $learner = $this->learner();
        $this->actingAs($learner)->postJson("/api/tutor/learning/courses/{$course->id}/enrol")->assertCreated();
        foreach (array_slice($this->lessonIds($course), 0, 2) as $id) {
            $this->postJson("/api/tutor/learning/courses/{$course->id}/lessons/{$id}/complete")->assertOk();
        }
        $this->postJson("/api/tutor/learning/courses/{$course->id}/lessons/{$quizLesson}/complete")->assertUnprocessable();

        $attempt = $this->postJson("/api/tutor/learning/assessments/{$quizId}/attempts")->assertCreated()->json('data.attempt_id');
        $this->postJson("/api/tutor/learning/attempts/{$attempt}/submit", ['answers' => [$questionId => '4']])->assertOk()->assertJsonPath('data.passed', false);
        $this->getJson("/api/tutor/learning/courses/{$course->id}/progress")->assertJsonPath('data.percent', 100)->assertJsonPath('data.certificate', null);

        $attempt = $this->postJson("/api/tutor/learning/assessments/{$quizId}/attempts")->assertCreated()->json('data.attempt_id');
        $this->postJson("/api/tutor/learning/attempts/{$attempt}/submit", ['answers' => [$questionId => '3']])->assertOk()->assertJsonPath('data.passed', true);
        $this->assertNotNull($this->getJson("/api/tutor/learning/courses/{$course->id}/progress")->json('data.certificate'));
    }

    public function test_only_enrolled_learners_who_have_started_can_review_a_course(): void
    {
        $tutor = $this->approvedTutor();
        $course = $this->publishedCourse($tutor);
        $learner = $this->learner();

        $this->actingAs($learner)->postJson("/api/tutor/learning/courses/{$course->id}/reviews", ['rating' => 5])->assertForbidden();
        $this->postJson("/api/tutor/learning/courses/{$course->id}/enrol")->assertCreated();
        $this->postJson("/api/tutor/learning/courses/{$course->id}/reviews", ['rating' => 5])->assertUnprocessable();
        $this->postJson("/api/tutor/learning/courses/{$course->id}/lessons/{$this->lessonIds($course)[0]}/complete")->assertOk();
        $this->postJson("/api/tutor/learning/courses/{$course->id}/reviews", ['rating' => 4, 'body' => 'Clear explanations.'])->assertCreated();
        $this->postJson("/api/tutor/learning/courses/{$course->id}/reviews", ['rating' => 5, 'body' => 'Even better after lesson 2.'])->assertCreated();

        $this->getJson("/api/tutor/learning/courses/{$course->id}/reviews")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.rating', 5);
        $this->getJson("/api/tutor/learning/courses/{$course->id}")->assertJsonPath('data.reviews.count', 1);
        $this->actingAs($tutor)->postJson("/api/tutor/learning/courses/{$course->id}/reviews", ['rating' => 5])->assertUnprocessable();
    }

    public function test_the_progress_feed_reports_recorded_events(): void
    {
        $course = $this->publishedCourse($this->approvedTutor(), ['completion_rule' => 'all_lessons']);
        $learner = $this->learner();
        $this->actingAs($learner)->postJson("/api/tutor/learning/courses/{$course->id}/enrol");
        foreach ($this->lessonIds($course) as $id) {
            $this->postJson("/api/tutor/learning/courses/{$course->id}/lessons/{$id}/complete");
        }

        $types = array_column(app(ProgressFeed::class)->recentEvents($learner), 'type');
        $this->assertContains('lesson_completed', $types);
        $this->assertContains('certificate_issued', $types);
        $this->assertSame([], app(ProgressFeed::class)->recentEvents($this->learner('Fresh')));
    }

    public function test_archived_courses_take_no_new_enrolments_but_keep_existing_learners(): void
    {
        $tutor = $this->approvedTutor();
        $course = $this->publishedCourse($tutor);
        $enrolled = $this->learner();
        $this->actingAs($enrolled)->postJson("/api/tutor/learning/courses/{$course->id}/enrol")->assertCreated();
        $this->actingAs($tutor)->postJson("/api/tutor/learning/studio/courses/{$course->id}/archive")->assertOk();

        $this->actingAs($this->learner('Late'))->postJson("/api/tutor/learning/courses/{$course->id}/enrol")->assertNotFound();
        $this->actingAs($enrolled)->getJson("/api/tutor/learning/courses/{$course->id}/learn/{$this->lessonIds($course)[1]}")->assertOk();
        $this->assertNotNull(app(CoursePublisher::class));
    }
}
