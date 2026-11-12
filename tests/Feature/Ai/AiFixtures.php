<?php

namespace Tests\Feature\Ai;

use App\Domain\Ai\Contracts\AiTutor;
use App\Domain\Learning\Models\Assessment;
use App\Domain\Learning\Models\Assignment;
use App\Domain\Learning\Models\Attempt;
use App\Domain\Learning\Models\AttemptAnswer;
use App\Domain\Learning\Models\Question;
use App\Domain\Learning\Models\QuestionBank;
use App\Models\Subject;
use App\Models\TutorProfile;
use App\Models\User;
use Tests\Feature\Safety\BuildsSafetyState;

/** Builders for the AI tests. Every test binds FakeAiTutor: nothing reaches the network. */
trait AiFixtures
{
    use BuildsSafetyState;

    protected FakeAiTutor $ai;

    protected function fakeAi(): FakeAiTutor
    {
        $this->ai = new FakeAiTutor;
        $this->app->instance(AiTutor::class, $this->ai);

        return $this->ai;
    }

    /** A child with a linked, consenting guardian (allowed to use the assistant). */
    protected function consentedChild(): User
    {
        $child = $this->child('Lerato');
        $this->linkGuardian($child, learnerAge: 9);

        return $child;
    }

    protected function tutorUser(string $name = 'Thandi Tutor'): User
    {
        $user = $this->adult($name);
        TutorProfile::create(['user_id' => $user->id, 'hourly_rate' => 200, 'status' => 'approved']);

        return $user;
    }

    protected function mathSubject(): Subject
    {
        return Subject::query()->firstOrCreate(['name' => 'Mathematics'], ['level' => 'high_school']);
    }

    /**
     * An assessment with one single-choice question.
     *
     * @return array{0: Assessment, 1: Question}
     */
    protected function assessmentWithQuestion(User $owner, string $kind = 'tutor_assessment', string $reveal = 'after_submit', string $prompt = 'Solve for x when 2x plus 6 equals 14 and show the value of x'): array
    {
        $bank = QuestionBank::create(['owner_id' => $owner->id, 'title' => 'Linear equations', 'subject_id' => $this->mathSubject()->id, 'strand' => 'Algebra', 'topic' => 'Linear equations']);
        $question = $bank->questions()->create([
            'type' => 'single_choice', 'prompt' => $prompt,
            'options' => [['key' => 'A', 'text' => 'x = 4'], ['key' => 'B', 'text' => 'x = 10']],
            'answer' => ['key' => 'A'], 'explanation' => 'Subtract 6, then divide by 2.', 'marks' => 1,
        ]);
        $assessment = Assessment::create(['owner_id' => $owner->id, 'title' => 'Algebra check', 'kind' => $kind, 'reveal_answers' => $reveal, 'pass_percent' => 50]);
        $assessment->questions()->attach($question->id, ['position' => 1]);

        return [$assessment, $question];
    }

    protected function assign(Assessment $assessment, User $learner, User $tutor): void
    {
        Assignment::create(['assessment_id' => $assessment->id, 'learner_id' => $learner->id, 'assigned_by' => $tutor->id]);
    }

    protected function submittedWrongAttempt(Assessment $assessment, Question $question, User $learner): Attempt
    {
        $attempt = Attempt::create(['assessment_id' => $assessment->id, 'user_id' => $learner->id, 'question_order' => [$question->id], 'status' => 'submitted',
            'started_at' => now()->subHour(), 'submitted_at' => now()->subMinutes(30), 'score' => 0, 'max_score' => 1, 'percent' => 0, 'passed' => false]);
        AttemptAnswer::create([
            'attempt_id' => $attempt->id, 'question_id' => $question->id, 'user_id' => $learner->id, 'subject_id' => $this->mathSubject()->id,
            'strand' => 'Algebra', 'topic' => 'Linear equations', 'response' => ['value' => 'B'], 'is_correct' => false,
            'marks_awarded' => 0, 'marks_possible' => 1, 'counts_for_mastery' => true, 'answered_at' => now()->subMinutes(30),
        ]);

        return $attempt;
    }

    /** @param  array<string, mixed>  $payload */
    protected function ask(array $payload)
    {
        return $this->postJson('/api/tutor/ai/ask', $payload);
    }
}
