<?php

namespace Tests\Feature\Learning;

use App\Domain\Learning\Models\Assessment;
use App\Domain\Learning\Models\Attempt;
use App\Domain\Learning\Models\AttemptAnswer;
use App\Domain\Learning\Models\Question;
use App\Domain\Learning\Models\QuestionBank;
use App\Domain\Learning\Services\Marker;
use App\Models\Subject;
use App\Models\TutorSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AssessmentAndMasteryTest extends TestCase
{
    use LearningFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('learning');
    }

    /** @return array{0: int, 1: array<string, int>} bank id and question ids by name */
    private function bankWithEveryType(User $tutor, string $topic = 'Quadratic equations', string $strand = 'Algebra'): array
    {
        $this->actingAs($tutor);
        $bank = $this->postJson('/api/tutor/learning/banks', ['title' => $topic, 'topic' => $topic, 'strand' => $strand, 'subject_id' => $this->subject()->id])->assertCreated()->json('data.id');
        $ids = [];
        $ids['single'] = $this->postJson("/api/tutor/learning/banks/{$bank}/questions", [
            'type' => 'single_choice', 'prompt' => 'Roots of x^2 - 5x + 6 = 0?',
            'options' => [['key' => 'a', 'text' => '2 and 3'], ['key' => 'b', 'text' => '-2 and -3']],
            'answer' => ['key' => 'a'], 'explanation' => '(x - 2)(x - 3) = 0',
        ])->assertCreated()->json('data.id');
        $ids['multi'] = $this->postJson("/api/tutor/learning/banks/{$bank}/questions", [
            'type' => 'multi_select', 'prompt' => 'Which are quadratic?',
            'options' => [['key' => 'a', 'text' => 'x^2 + 1'], ['key' => 'b', 'text' => '2x + 1'], ['key' => 'c', 'text' => '3x^2']],
            'answer' => ['keys' => ['c', 'a']], 'marks' => 2,
        ])->assertCreated()->json('data.id');
        $ids['numeric'] = $this->postJson("/api/tutor/learning/banks/{$bank}/questions", [
            'type' => 'numeric', 'prompt' => 'Positive root of x^2 = 2, to 2 decimals', 'answer' => ['value' => 1.41, 'tolerance' => 0.01],
        ])->assertCreated()->json('data.id');
        $ids['text'] = $this->postJson("/api/tutor/learning/banks/{$bank}/questions", [
            'type' => 'short_text', 'prompt' => 'Name of b^2 - 4ac', 'answer' => ['accepted' => ['discriminant', 'the discriminant']],
        ])->assertCreated()->json('data.id');

        return [$bank, $ids];
    }

    public function test_marking_rules_for_every_question_type(): void
    {
        $marker = app(Marker::class);
        $q = fn (string $type, array $answer, int $marks = 1) => new Question(['type' => $type, 'answer' => $answer, 'marks' => $marks]);

        $this->assertTrue($marker->mark($q('single_choice', ['key' => 'a']), 'a')['correct']);
        $this->assertFalse($marker->mark($q('single_choice', ['key' => 'a']), ['a'])['correct']);
        $this->assertSame(2, $marker->mark($q('multi_select', ['keys' => ['a', 'c']], 2), ['c', 'a'])['marks']);
        $this->assertFalse($marker->mark($q('multi_select', ['keys' => ['a', 'c']]), ['a', 'b', 'c'])['correct'], 'Ticking every option earns nothing.');
        $this->assertFalse($marker->mark($q('multi_select', ['keys' => ['a', 'c']]), ['a'])['correct'], 'No partial credit.');
        $this->assertTrue($marker->mark($q('numeric', ['value' => 1.41, 'tolerance' => 0.01]), '1,415')['correct']);
        $this->assertTrue($marker->mark($q('numeric', ['value' => 1.41, 'tolerance' => 0.01]), 1.42)['correct']);
        $this->assertFalse($marker->mark($q('numeric', ['value' => 1.41, 'tolerance' => 0.01]), '1.43')['correct']);
        $this->assertFalse($marker->mark($q('numeric', ['value' => 3, 'tolerance' => 0]), 'three')['correct']);
        $this->assertTrue($marker->mark($q('short_text', ['accepted' => ['The discriminant']]), '  the   DISCRIMINANT. ')['correct']);
        $this->assertFalse($marker->mark($q('short_text', ['accepted' => ['discriminant']]), '')['correct']);
    }

    public function test_answer_keys_are_validated_when_authoring(): void
    {
        $tutor = $this->approvedTutor();
        [$bank] = $this->bankWithEveryType($tutor);
        $this->postJson("/api/tutor/learning/banks/{$bank}/questions", ['type' => 'single_choice', 'prompt' => 'P', 'options' => [['key' => 'a', 'text' => 'A'], ['key' => 'b', 'text' => 'B']], 'answer' => ['key' => 'z']])->assertUnprocessable();
        $this->postJson("/api/tutor/learning/banks/{$bank}/questions", ['type' => 'numeric', 'prompt' => 'P', 'answer' => ['value' => 'x']])->assertUnprocessable();
        $this->postJson("/api/tutor/learning/banks/{$bank}/questions", ['type' => 'short_text', 'prompt' => 'P', 'answer' => ['accepted' => ['  ']]])->assertUnprocessable();
        $this->actingAs($this->learner())->getJson("/api/tutor/learning/banks/{$bank}")->assertNotFound();
    }

    public function test_an_assigned_learner_attempts_and_the_server_marks_without_ever_sending_the_key_first(): void
    {
        $tutor = $this->approvedTutor();
        [, $ids] = $this->bankWithEveryType($tutor);
        $assessment = $this->postJson('/api/tutor/learning/assessments', ['title' => 'Quadratics check', 'kind' => 'tutor_assessment', 'pass_percent' => 60, 'max_attempts' => 2])->assertCreated()->json('data.id');
        $this->postJson("/api/tutor/learning/assessments/{$assessment}/questions", ['question_ids' => array_values($ids)])->assertOk()->assertJsonPath('data.questions', 4);

        $learner = $this->learner();
        $stranger = $this->learner('Stranger');
        // No teaching relationship yet: assignment refused.
        $this->postJson("/api/tutor/learning/assessments/{$assessment}/assign", ['learner_id' => $learner->id])->assertForbidden()->assertJsonPath('error', 'no_relationship');
        $this->bookedSession($tutor, $learner);
        $this->postJson("/api/tutor/learning/assessments/{$assessment}/assign", ['learner_id' => $learner->id])->assertCreated();

        $this->actingAs($stranger)->postJson("/api/tutor/learning/assessments/{$assessment}/attempts")->assertForbidden();

        $this->actingAs($learner);
        $this->getJson('/api/tutor/learning/assessments')->assertJsonPath('data.assigned_to_me.0.assessment.title', 'Quadratics check');
        $start = $this->postJson("/api/tutor/learning/assessments/{$assessment}/attempts")->assertCreated();
        $this->assertStringNotContainsString('"answer"', $start->getContent());
        $this->assertStringNotContainsString('discriminant', $start->getContent());
        $this->assertStringNotContainsString('(x - 2)(x - 3)', $start->getContent());
        $attemptId = $start->json('data.attempt_id');
        $this->assertCount(4, $start->json('data.questions'));
        // Starting again resumes the same attempt.
        $this->postJson("/api/tutor/learning/assessments/{$assessment}/attempts")->assertJsonPath('data.attempt_id', $attemptId);

        $result = $this->postJson("/api/tutor/learning/attempts/{$attemptId}/submit", ['answers' => [
            $ids['single'] => 'a',
            $ids['multi'] => ['a'],          // incomplete: 0 of 2
            $ids['numeric'] => '1.41',
            $ids['text'] => 'Discriminant',
        ]])->assertOk();
        $result->assertJsonPath('data.score', 3)->assertJsonPath('data.max_score', 5)->assertJsonPath('data.percent', 60)
            ->assertJsonPath('data.passed', true)->assertJsonPath('data.result_kind', 'assessed');
        $this->assertNotEmpty(collect($result->json('data.questions'))->firstWhere('id', $ids['single'])['explanation']);

        $this->postJson("/api/tutor/learning/attempts/{$attemptId}/submit", ['answers' => []])->assertUnprocessable();
        $this->actingAs($stranger)->getJson("/api/tutor/learning/attempts/{$attemptId}")->assertNotFound();
        $this->actingAs($tutor)->getJson("/api/tutor/learning/assessments/{$assessment}/attempts")->assertOk()->assertJsonPath('data.0.percent', 60);

        // Two attempts maximum.
        $this->actingAs($learner);
        $second = $this->postJson("/api/tutor/learning/assessments/{$assessment}/attempts")->assertCreated()->json('data.attempt_id');
        $this->postJson("/api/tutor/learning/attempts/{$second}/submit", ['answers' => []])->assertOk()->assertJsonPath('data.score', 0);
        $this->postJson("/api/tutor/learning/assessments/{$assessment}/attempts")->assertUnprocessable();
    }

    public function test_the_time_limit_is_enforced_by_the_server_clock(): void
    {
        $tutor = $this->approvedTutor();
        [, $ids] = $this->bankWithEveryType($tutor);
        $assessment = $this->postJson('/api/tutor/learning/assessments', ['title' => 'Timed', 'kind' => 'practice', 'time_limit_minutes' => 10, 'reveal_answers' => 'never'])->json('data.id');
        $this->postJson("/api/tutor/learning/assessments/{$assessment}/questions", ['question_ids' => [$ids['single']]]);
        $learner = $this->learner();
        $this->bookedSession($tutor, $learner);
        $this->postJson("/api/tutor/learning/assessments/{$assessment}/assign", ['learner_id' => $learner->id])->assertCreated();

        $attempt = $this->actingAs($learner)->postJson("/api/tutor/learning/assessments/{$assessment}/attempts")->json('data.attempt_id');
        $this->travel(11)->minutes();
        $result = $this->postJson("/api/tutor/learning/attempts/{$attempt}/submit", ['answers' => [$ids['single'] => 'a']])->assertOk()
            ->assertJsonPath('data.late', true)->assertJsonPath('data.passed', false)->assertJsonPath('data.score', 1)
            ->assertJsonPath('data.result_kind', 'practice');
        $this->assertArrayNotHasKey('answer_key', $result->json('data.questions.0'));
    }

    public function test_mastery_and_insights_are_computed_from_real_answers(): void
    {
        $learner = $this->learner();
        $subject = $this->subject();

        $empty = $this->actingAs($learner)->getJson('/api/tutor/learning/progress')->assertOk();
        $empty->assertJsonPath('data.mastery', [])->assertJsonPath('data.insights.sentences.0', 'There are no marked answers yet. Insights appear after you complete quizzes or practice.');

        // Linear equations: 3 wrong then 3 right. Quadratic equations: 1 of 5 right. Trig: only 2 answers.
        $this->answers($learner, $subject, 'Algebra', 'Linear equations', [false, false, false, true, true, true]);
        $this->answers($learner, $subject, 'Algebra', 'Quadratic equations', [false, true, false, false, false]);
        $this->answers($learner, $subject, 'Trigonometry', 'Trig ratios', [true, true]);

        $data = $this->getJson('/api/tutor/learning/progress')->assertOk()->json('data');
        $byTopic = collect($data['mastery'])->keyBy('topic');
        $this->assertSame('improving', $byTopic['Linear equations']['trend']);
        $this->assertSame(50, $byTopic['Linear equations']['recent_accuracy_percent']);
        $this->assertSame('needs_attention', $byTopic['Quadratic equations']['level']);
        $this->assertSame(20, $byTopic['Quadratic equations']['recent_accuracy_percent']);
        $this->assertSame('not_enough_evidence', $byTopic['Trig ratios']['level']);
        $this->assertNull($byTopic['Trig ratios']['recent_accuracy_percent']);

        $this->assertSame('You have improved in Algebra; Quadratic equations still needs attention.', $data['insights']['sentences'][0]);
        $this->assertContains('Linear equations: 0% of marks earlier, 100% in your most recent answers.', $data['insights']['sentences']);
        $this->assertContains('Not enough answers yet to say anything about Trig ratios (at least 3 needed).', $data['insights']['sentences']);
        $this->assertSame('Computed from your answers. Not AI-generated, and not an official exam result.', $data['insights']['label']);
        $this->assertSame(13, $data['insights']['based_on']['answers']);

        // Deterministic: the same evidence gives the same words.
        $this->assertSame($data['insights'], $this->getJson('/api/tutor/learning/progress')->json('data.insights'));
    }

    public function test_the_creators_own_trial_attempts_are_not_evidence(): void
    {
        $tutor = $this->approvedTutor();
        [, $ids] = $this->bankWithEveryType($tutor);
        $assessment = $this->postJson('/api/tutor/learning/assessments', ['title' => 'Try', 'kind' => 'practice'])->json('data.id');
        $this->postJson("/api/tutor/learning/assessments/{$assessment}/questions", ['question_ids' => [$ids['single']]]);
        $attempt = $this->postJson("/api/tutor/learning/assessments/{$assessment}/attempts")->assertCreated()->json('data.attempt_id');
        $this->postJson("/api/tutor/learning/attempts/{$attempt}/submit", ['answers' => [$ids['single'] => 'a']])->assertOk();

        $this->getJson('/api/tutor/learning/progress')->assertJsonPath('data.mastery', []);
    }

    public function test_assessments_reject_other_peoples_questions_and_courses(): void
    {
        $tutor = $this->approvedTutor();
        [, $ids] = $this->bankWithEveryType($tutor);
        $other = $this->approvedTutor('Other');
        $this->actingAs($other);
        $mine = $this->postJson('/api/tutor/learning/assessments', ['title' => 'Mine', 'kind' => 'tutor_assessment'])->json('data.id');
        $this->postJson("/api/tutor/learning/assessments/{$mine}/questions", ['question_ids' => [$ids['single']]])->assertUnprocessable();
        $course = $this->draftCourse($tutor);
        $this->postJson('/api/tutor/learning/assessments', ['title' => 'Q', 'kind' => 'course_quiz', 'course_id' => $course->id])->assertUnprocessable();
        $this->getJson("/api/tutor/learning/assessments/{$mine}")->assertOk();
        $this->actingAs($tutor)->getJson("/api/tutor/learning/assessments/{$mine}")->assertForbidden();
    }

    public function test_flashcards_follow_sm2_spacing(): void
    {
        $learner = $this->learner();
        $this->actingAs($learner);
        $set = $this->postJson('/api/tutor/learning/flashcards', ['title' => 'Cell biology', 'cards' => [
            ['front' => 'Mitochondria', 'back' => 'Site of cellular respiration'],
            ['front' => 'Ribosome', 'back' => 'Protein synthesis'],
        ]])->assertCreated()->json('data');
        $cardId = $set['cards'][0]['id'];
        $this->getJson("/api/tutor/learning/flashcards/{$set['id']}/due")->assertJsonCount(2, 'data.due');

        $this->postJson("/api/tutor/learning/flashcards/cards/{$cardId}/review", ['grade' => 5])->assertJsonPath('data.interval_days', 1)->assertJsonPath('data.ease', 2.6);
        $this->getJson("/api/tutor/learning/flashcards/{$set['id']}/due")->assertJsonCount(1, 'data.due');
        $this->postJson("/api/tutor/learning/flashcards/cards/{$cardId}/review", ['grade' => 5])->assertJsonPath('data.interval_days', 6);
        $this->postJson("/api/tutor/learning/flashcards/cards/{$cardId}/review", ['grade' => 5])->assertJsonPath('data.interval_days', 16);
        $this->postJson("/api/tutor/learning/flashcards/cards/{$cardId}/review", ['grade' => 1])->assertJsonPath('data.interval_days', 1)->assertJsonPath('data.repetitions', 0);

        $this->travel(2)->days();
        $this->getJson("/api/tutor/learning/flashcards/{$set['id']}/due")->assertJsonCount(2, 'data.due');

        // Private sets are invisible to others.
        $this->actingAs($this->learner('Other'))->getJson("/api/tutor/learning/flashcards/{$set['id']}/due")->assertNotFound();
    }

    private function bookedSession(User $tutor, User $learner): void
    {
        TutorSession::create([
            'tutor_profile_id' => $tutor->tutorProfile->id,
            'student_id' => $learner->id,
            'subject_id' => $this->subject()->id,
            'status' => 'completed',
            'starts_at' => now()->subDay(),
            'duration_minutes' => 60,
            'rate' => 200,
        ]);
    }

    /** @param  list<bool>  $results  oldest first */
    private function answers(User $learner, Subject $subject, string $strand, string $topic, array $results): void
    {
        $owner = $this->learner('Bank owner '.$topic);
        $bank = QuestionBank::create(['owner_id' => $owner->id, 'title' => $topic, 'subject_id' => $subject->id, 'strand' => $strand, 'topic' => $topic]);
        $question = $bank->questions()->create(['type' => 'single_choice', 'prompt' => 'Q', 'options' => [['key' => 'a', 'text' => 'A'], ['key' => 'b', 'text' => 'B']], 'answer' => ['key' => 'a']]);
        $assessment = Assessment::create(['owner_id' => $owner->id, 'title' => $topic, 'kind' => 'practice']);
        foreach ($results as $i => $correct) {
            $at = now()->subDays(count($results) - $i);
            $attempt = Attempt::create(['assessment_id' => $assessment->id, 'user_id' => $learner->id, 'question_order' => [$question->id], 'status' => 'submitted', 'started_at' => $at, 'submitted_at' => $at]);
            AttemptAnswer::create([
                'attempt_id' => $attempt->id, 'question_id' => $question->id, 'user_id' => $learner->id, 'subject_id' => $subject->id,
                'strand' => $strand, 'topic' => $topic, 'response' => ['value' => $correct ? 'a' : 'b'], 'is_correct' => $correct,
                'marks_awarded' => $correct ? 1 : 0, 'marks_possible' => 1, 'counts_for_mastery' => true, 'answered_at' => $at,
            ]);
        }
    }
}
