<?php

namespace Tests\Feature\Ai;

use App\Domain\Ai\Models\AiQuestionDraft;
use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\Enrolment;
use App\Domain\Learning\Models\LibraryResource;
use App\Domain\Learning\Models\Question;
use App\Domain\Learning\Models\QuestionBank;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Tutor assistance: drafts never published, real mastery, real library (FakeAiTutor; no network). */
class TutorAssistanceTest extends TestCase
{
    use AiFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeAi();
    }

    private function draftPayload(): array
    {
        $empty = ['options' => [], 'correct_keys' => [], 'numeric_answer' => '', 'tolerance' => '', 'accepted_answers' => [], 'explanation' => 'Because.', 'difficulty' => 'standard'];

        return ['questions' => [
            ['type' => 'single_choice', 'prompt' => 'What is 2 + 2?', 'options' => [['key' => 'A', 'text' => '3'], ['key' => 'B', 'text' => '4']], 'correct_keys' => ['B']] + $empty,
            ['type' => 'numeric', 'prompt' => 'Solve 2x = 9', 'numeric_answer' => '4.5', 'tolerance' => '0'] + $empty,
            ['type' => 'single_choice', 'prompt' => 'Broken key', 'options' => [['key' => 'A', 'text' => 'x'], ['key' => 'B', 'text' => 'y']], 'correct_keys' => ['Z']] + $empty,
        ]];
    }

    public function test_quiz_drafts_wait_for_the_tutor_and_only_valid_keys_are_kept(): void
    {
        $tutor = $this->tutorUser();
        $bank = QuestionBank::create(['owner_id' => $tutor->id, 'title' => 'Basics', 'topic' => 'Arithmetic']);
        $this->ai->queue(FakeAiTutor::ok('', $this->draftPayload()));

        $this->actingAs($tutor);
        $this->postJson('/api/tutor/ai/tutor/ask', ['task' => 'quiz_drafts', 'bank_id' => $bank->id, 'count' => 3])
            ->assertCreated()->assertJsonCount(2, 'data.drafts')->assertJsonPath('data.drafts.0.status', 'draft');
        $this->assertSame(0, Question::count(), 'Drafts are not questions until the tutor accepts them.');

        [$first, $second] = AiQuestionDraft::orderBy('id')->get()->all();
        $this->postJson("/api/tutor/ai/drafts/{$first->id}/accept", ['prompt' => 'What is 2 + 2? (edited)'])->assertOk()->assertJsonPath('data.status', 'accepted');
        $question = Question::sole();
        $this->assertSame(['What is 2 + 2? (edited)', ['key' => 'B'], $bank->id], [$question->prompt, $question->answer, $question->bank_id]);
        $this->postJson("/api/tutor/ai/drafts/{$first->id}/accept")->assertUnprocessable();

        $this->postJson("/api/tutor/ai/drafts/{$second->id}/discard")->assertOk()->assertJsonPath('data.status', 'discarded');
        $this->assertSame(1, Question::count());

        $this->actingAs($this->tutorUser('Other tutor'));
        $this->postJson('/api/tutor/ai/tutor/ask', ['task' => 'quiz_drafts', 'bank_id' => $bank->id])->assertNotFound()->assertJsonPath('error', 'not_your_bank');
        $this->postJson("/api/tutor/ai/drafts/{$second->id}/accept")->assertNotFound();
    }

    public function test_tutor_tools_need_a_tutor_profile(): void
    {
        $this->actingAs($this->adult('Not a tutor'));
        $this->postJson('/api/tutor/ai/tutor/ask', ['task' => 'lesson_outline', 'text' => 'Fractions'])->assertForbidden()->assertJsonPath('error', 'not_a_tutor');
        $this->assertSame([], $this->ai->prompts);
    }

    public function test_struggles_are_aggregated_from_real_mastery_without_names(): void
    {
        $tutor = $this->tutorUser();
        $this->actingAs($tutor);
        $this->postJson('/api/tutor/ai/tutor/ask', ['task' => 'struggle_summary'])->assertUnprocessable()->assertJsonPath('error', 'no_evidence');

        $learner = $this->adult('Zanele Learner');
        [$assessment, $question] = $this->assessmentWithQuestion($tutor, 'tutor_assessment');
        $this->assign($assessment, $learner, $tutor);
        foreach (range(1, 3) as $i) {
            $this->submittedWrongAttempt($assessment, $question, $learner);
        }

        $this->getJson('/api/tutor/ai/tutor')->assertOk()
            ->assertJsonPath('data.struggles.learners', 1)
            ->assertJsonPath('data.struggles.topics.0.topic', 'Linear equations')
            ->assertJsonPath('data.struggles.topics.0.needs_attention', 1);

        $this->postJson('/api/tutor/ai/tutor/ask', ['task' => 'struggle_summary'])->assertCreated();
        $this->assertStringContainsString('Linear equations: 1 learner(s) with evidence; needs attention 1', $this->ai->lastText());
        $this->assertStringNotContainsString('Zanele', $this->ai->lastText());
    }

    public function test_resource_suggestions_keep_only_real_library_resources(): void
    {
        $tutor = $this->tutorUser();
        $subject = $this->mathSubject();
        $resource = LibraryResource::create(['owner_id' => $tutor->id, 'title' => 'Fractions worksheet', 'description' => 'Practice', 'type' => 'worksheet',
            'subject_id' => $subject->id, 'topic' => 'Fractions', 'language' => 'en', 'ownership' => 'own_work', 'licence' => 'cc_by', 'is_free' => true,
            'status' => 'published', 'quality_tier' => 'community', 'path' => 'x.pdf', 'mime' => 'application/pdf', 'size' => 10, 'sha256' => str_repeat('c', 64), 'original_name' => 'x.pdf', 'currency' => 'ZAR']);
        $this->ai->queue(FakeAiTutor::ok('', ['suggestions' => [['resource_id' => $resource->id, 'why' => 'Matches fractions.'], ['resource_id' => 99999, 'why' => 'Invented']]]));

        $this->actingAs($tutor);
        $this->postJson('/api/tutor/ai/tutor/ask', ['task' => 'resource_suggestions', 'text' => 'Fractions', 'subject_id' => $subject->id])
            ->assertCreated()->assertJsonCount(1, 'data.data.suggestions')->assertJsonPath('data.data.suggestions.0.title', 'Fractions worksheet');
        $this->assertStringContainsString('Fractions worksheet', $this->ai->lastText());
    }

    public function test_course_descriptions_are_only_for_your_own_courses_and_never_saved_automatically(): void
    {
        $tutor = $this->tutorUser();
        $course = Course::create(['owner_id' => $tutor->id, 'slug' => 'c-1', 'title' => 'Grade 9 Geometry', 'description' => 'Old text',
            'original_locale' => 'en', 'objectives' => ['Angles'], 'pricing' => 'free', 'currency' => 'ZAR', 'status' => 'draft']);
        $this->actingAs($tutor);
        $this->postJson('/api/tutor/ai/tutor/ask', ['task' => 'course_description', 'course_id' => $course->id])->assertCreated();
        $this->assertStringContainsString('Title: Grade 9 Geometry', $this->ai->lastText());
        $this->assertSame('Old text', $course->fresh()->description);

        $this->actingAs($this->tutorUser('Other'));
        $this->postJson('/api/tutor/ai/tutor/ask', ['task' => 'course_description', 'course_id' => $course->id])->assertNotFound();
        $this->assertSame(0, Enrolment::count());
    }

    public function test_the_tutor_screen_renders_with_drafts(): void
    {
        $tutor = $this->tutorUser();
        $bank = QuestionBank::create(['owner_id' => $tutor->id, 'title' => 'Basics', 'topic' => 'Arithmetic']);
        $this->ai->queue(FakeAiTutor::ok('', $this->draftPayload()));
        $this->actingAs($tutor);
        $this->postJson('/api/tutor/ai/tutor/ask', ['task' => 'quiz_drafts', 'bank_id' => $bank->id]);

        $this->get('/ai/tutor')->assertOk()->assertSee('Question drafts to review')->assertSee('What is 2 + 2?')->assertSee('Add to my bank');
    }
}
