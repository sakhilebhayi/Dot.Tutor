<?php

namespace Tests\Feature\Ai;

use App\Domain\Academic\Models\Language;
use App\Domain\Ai\Models\AiInteraction;
use App\Domain\Learning\Models\LibraryResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Student assistance grounded in real records: attempts, mastery, library resources (FakeAiTutor; no network). */
class StudentAssistanceTest extends TestCase
{
    use AiFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeAi();
        Storage::fake('learning');
    }

    public function test_explaining_a_mistake_uses_the_learners_real_answer_and_the_key_only_when_revealed(): void
    {
        $tutor = $this->tutorUser();
        $learner = $this->adult('Learner');
        [$assessment, $question] = $this->assessmentWithQuestion($tutor, 'tutor_assessment', 'after_submit');
        $attempt = $this->submittedWrongAttempt($assessment, $question, $learner);

        $this->actingAs($learner);
        $this->ask(['task' => 'explain_mistake', 'attempt_id' => $attempt->id, 'question_id' => $question->id])->assertCreated();
        $sent = $this->ai->lastText();
        $this->assertStringContainsString('The learner answered: B) x = 10', $sent);
        $this->assertStringContainsString('Correct answer (from the answer key): A) x = 4', $sent);
        $this->assertStringContainsString('Subtract 6, then divide by 2.', $sent);

        [$hidden, $hiddenQuestion] = $this->assessmentWithQuestion($tutor, 'tutor_assessment', 'never', 'Which value of x makes 3x equal to 21 in this equation');
        $hiddenAttempt = $this->submittedWrongAttempt($hidden, $hiddenQuestion, $learner);
        $this->ask(['task' => 'explain_mistake', 'attempt_id' => $hiddenAttempt->id, 'question_id' => $hiddenQuestion->id])->assertCreated();
        $this->assertStringNotContainsString('Correct answer', $this->ai->lastText());
        $this->assertStringNotContainsString('Subtract 6', $this->ai->lastText());
        $this->assertStringContainsString('chosen not to show the correct answer', $this->ai->lastText());
    }

    public function test_mistakes_are_explained_only_for_your_own_submitted_wrong_answers(): void
    {
        $tutor = $this->tutorUser();
        $learner = $this->adult('Learner');
        [$assessment, $question] = $this->assessmentWithQuestion($tutor);
        $attempt = $this->submittedWrongAttempt($assessment, $question, $learner);

        $this->actingAs($this->adult('Someone else'));
        $this->ask(['task' => 'explain_mistake', 'attempt_id' => $attempt->id, 'question_id' => $question->id])->assertNotFound()->assertJsonPath('error', 'not_your_attempt');

        $attempt->answers()->update(['is_correct' => true, 'marks_awarded' => 1]);
        $this->actingAs($learner);
        $this->ask(['task' => 'explain_mistake', 'attempt_id' => $attempt->id, 'question_id' => $question->id])->assertUnprocessable()->assertJsonPath('error', 'answer_was_correct');

        $attempt->forceFill(['status' => 'in_progress'])->save();
        $this->ask(['task' => 'explain_mistake', 'attempt_id' => $attempt->id, 'question_id' => $question->id])->assertUnprocessable()->assertJsonPath('error', 'attempt_not_submitted');
        $this->assertSame([], $this->ai->prompts);
    }

    public function test_a_study_plan_is_built_from_measured_mastery_and_says_when_there_is_none(): void
    {
        $tutor = $this->tutorUser();
        $learner = $this->adult('Learner');
        $this->actingAs($learner);
        $this->ask(['task' => 'study_plan', 'text' => 'Pass my June exams', 'weeks' => 3])->assertCreated();
        $this->assertStringContainsString('No marked answers yet', $this->ai->lastText());

        [$assessment, $question] = $this->assessmentWithQuestion($tutor, 'practice');
        foreach (range(1, 3) as $i) {
            $this->submittedWrongAttempt($assessment, $question, $learner);
        }
        $this->ask(['task' => 'study_plan', 'text' => 'Pass my June exams'])->assertCreated();
        $this->assertStringContainsString('- Linear equations (Algebra): needs attention, recent accuracy 0%', $this->ai->lastText());
    }

    public function test_practice_questions_come_back_structured_with_answers_separate(): void
    {
        $this->ai->queue(FakeAiTutor::ok('', ['questions' => [['question' => 'What is 1/2 + 1/4?', 'hint' => 'Common denominator', 'answer' => '3/4', 'explanation' => 'Convert 1/2 to 2/4.']]]));
        $this->actingAs($this->adult());
        $id = $this->ask(['task' => 'practice', 'text' => 'Adding fractions', 'count' => 1])->assertCreated()
            ->assertJsonPath('data.data.questions.0.answer', '3/4')->json('data.id');
        $this->assertNotNull(end($this->ai->prompts)->schema);
        $this->get("/ai/interactions/{$id}")->assertOk()->assertSee('Answer and explanation');
    }

    public function test_resource_help_reads_only_files_the_learner_may_open(): void
    {
        $owner = $this->tutorUser('Owner');
        Storage::disk('learning')->put('library/notes.pdf', "%PDF-1.4\n%%EOF\n");
        $base = ['owner_id' => $owner->id, 'title' => 'Cell biology notes', 'description' => 'Notes', 'type' => 'notes', 'language' => 'en',
            'ownership' => 'own_work', 'licence' => 'cc_by', 'status' => 'published', 'quality_tier' => 'community', 'path' => 'library/notes.pdf',
            'mime' => 'application/pdf', 'size' => 20, 'sha256' => str_repeat('a', 64), 'original_name' => 'notes.pdf', 'currency' => 'ZAR'];
        $free = LibraryResource::create($base + ['is_free' => true]);
        $paid = LibraryResource::create(array_merge($base, ['title' => 'Paid notes', 'is_free' => false, 'price_minor' => 5000, 'sha256' => str_repeat('b', 64)]));

        $this->actingAs($this->adult('Reader'));
        $this->ask(['task' => 'resource_help', 'resource_id' => $free->id, 'text' => 'What does the mitochondria do?'])->assertCreated();
        $blocks = end($this->ai->prompts)->blocks;
        $this->assertSame('document', $blocks[0]['type']);
        $this->assertSame(base64_encode("%PDF-1.4\n%%EOF\n"), $blocks[0]['data']);
        $this->assertStringNotContainsString('%PDF', AiInteraction::query()->latest('id')->first()->prompt_text, 'The log keeps a reference, never the file.');

        $this->ask(['task' => 'resource_help', 'resource_id' => $paid->id, 'text' => 'Summarise it'])->assertNotFound()->assertJsonPath('error', 'not_found');
        $this->assertCount(1, $this->ai->prompts);
    }

    public function test_answers_can_be_requested_in_a_preferred_language_and_say_how_reliable_that_is(): void
    {
        Language::create(['code' => 'zu', 'name_en' => 'isiZulu', 'name_native' => 'isiZulu', 'script' => 'Latn', 'modality' => 'written', 'is_official_za' => true, 'is_active' => true, 'sort' => 2]);
        $this->actingAs($this->adult());
        $this->ask(['task' => 'explain', 'text' => 'What is an equation? Use $x^2$.', 'language' => 'zu'])->assertCreated()
            ->assertJsonPath('data.language', 'zu')
            ->assertJsonPath('data.language_note', fn ($note) => str_contains($note, 'not been checked by a native-speaking educator'));
        $this->assertStringContainsString('Write your whole answer in isiZulu (zu)', $this->ai->lastSystem());
        $this->assertStringContainsString('LaTeX', $this->ai->lastSystem());

        $this->ask(['task' => 'explain', 'text' => 'Hi', 'language' => 'xx'])->assertCreated()->assertJsonPath('data.language', 'en');
    }
}
