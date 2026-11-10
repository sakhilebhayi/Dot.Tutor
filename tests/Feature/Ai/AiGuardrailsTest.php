<?php

namespace Tests\Feature\Ai;

use App\Domain\Ai\Models\AiInteraction;
use App\Domain\Learning\Models\Attempt;
use App\Domain\Safety\Models\AccountRestriction;
use App\Domain\Safety\Models\Report;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Brief section 14 guardrails, enforced in code before anything is sent (FakeAiTutor; no network). */
class AiGuardrailsTest extends TestCase
{
    use AiFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeAi();
    }

    public function test_every_answer_is_labelled_ai_guidance_with_a_tutor_and_a_report_route(): void
    {
        $this->actingAs($this->adult());
        $this->ask(['task' => 'explain', 'text' => 'Why does ice float on water?'])
            ->assertCreated()
            ->assertJsonPath('data.label', 'AI guidance')
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.model', 'claude-opus-5-5')
            ->assertJsonPath('data.disclaimer', AiInteraction::DISCLAIMER)
            ->assertJsonStructure(['data' => ['ask_a_tutor_url', 'report_url']]);

        $row = AiInteraction::sole();
        $this->assertTrue($row->reached_model);
        $this->assertSame(1000 * 4 + 500 * 20, $row->cost_micro_usd, 'Opus 5.5 at $4/$20 per million tokens');
        $this->assertNotNull($row->purge_after);
        $this->assertStringContainsString('<learner_request>', $this->ai->lastText());
    }

    public function test_age_bands_change_the_rules_the_model_is_given(): void
    {
        $this->actingAs($this->consentedChild());
        $this->ask(['task' => 'explain', 'text' => 'What is a fraction?'])->assertCreated();
        $this->assertStringContainsString('young learner', $this->ai->lastSystem());
        $this->assertStringContainsString('Childline free on 116', $this->ai->lastSystem());

        $this->actingAs($this->adult('Grown up'));
        $this->ask(['task' => 'explain', 'text' => 'What is a fraction?'])->assertCreated();
        $this->assertStringNotContainsString('young learner', $this->ai->lastSystem());
        $this->assertStringContainsString('Age group: an adult', $this->ai->lastSystem());
    }

    public function test_an_unknown_age_is_answered_with_the_child_rules_and_says_so(): void
    {
        $this->actingAs($this->makeUser('No birth date'));
        $this->ask(['task' => 'explain', 'text' => 'What is a verb?'])->assertCreated();
        $this->assertStringContainsString('young learner', $this->ai->lastSystem());
        $this->assertFalse(AiInteraction::sole()->age_known);
        $this->getJson('/api/tutor/ai')->assertJsonPath('data.band', 'child')->assertJsonPath('data.modes', ['understand']);
    }

    public function test_a_minor_without_guardian_consent_and_a_suspended_account_are_refused_before_sending(): void
    {
        $this->actingAs($this->teen());
        $this->ask(['task' => 'explain', 'text' => 'Explain osmosis'])
            ->assertForbidden()->assertJsonPath('error', 'guardian_consent_required');

        $adult = $this->adult('Suspended');
        AccountRestriction::create(['user_id' => $adult->id, 'kind' => 'suspension', 'reason' => 'test', 'starts_at' => now()->subDay(), 'imposed_by_id' => $this->adult('Op')->id]);
        $this->actingAs($adult);
        $this->ask(['task' => 'explain', 'text' => 'Explain osmosis'])->assertForbidden()->assertJsonPath('error', 'account_suspended');

        $this->assertSame([], $this->ai->prompts);
        $this->assertSame(2, AiInteraction::where('status', 'refused')->count(), 'Refusals are logged for safeguarding review.');
    }

    public function test_help_me_understand_is_the_only_mode_for_minors(): void
    {
        $this->actingAs($this->consentedChild());
        $this->ask(['task' => 'explain', 'text' => 'Solve 3x = 12', 'mode' => 'worked_example'])->assertCreated()->assertJsonPath('data.mode', 'understand');
        $this->assertStringContainsString('do not give its final answer', $this->ai->lastSystem());

        $this->actingAs($this->adult());
        $this->ask(['task' => 'explain', 'text' => 'Solve 3x = 12', 'mode' => 'worked_example'])->assertCreated()->assertJsonPath('data.mode', 'worked_example');
        $this->assertStringContainsString('worked example', $this->ai->lastSystem());
    }

    public function test_a_timed_assessment_in_progress_pauses_the_assistant(): void
    {
        $tutor = $this->tutorUser();
        $learner = $this->adult('Learner');
        [$assessment, $question] = $this->assessmentWithQuestion($tutor);
        Attempt::create(['assessment_id' => $assessment->id, 'user_id' => $learner->id, 'question_order' => [$question->id], 'status' => 'in_progress',
            'started_at' => now()->subMinutes(5), 'deadline_at' => now()->addMinutes(25)]);

        $this->actingAs($learner);
        $this->ask(['task' => 'explain', 'text' => 'What is photosynthesis?'])
            ->assertUnprocessable()->assertJsonPath('error', 'assessment_in_progress');
        $this->assertSame([], $this->ai->prompts);
    }

    public function test_a_copied_question_from_an_assigned_assessment_is_refused(): void
    {
        $tutor = $this->tutorUser();
        $learner = $this->adult('Learner');
        [$assessment] = $this->assessmentWithQuestion($tutor);
        $this->assign($assessment, $learner, $tutor);

        $this->actingAs($learner);
        $this->ask(['task' => 'explain', 'text' => 'Please answer: Solve for x when 2x plus 6 equals 14 and show the value of x.'])
            ->assertUnprocessable()->assertJsonPath('error', 'assessment_question');
        $this->ask(['task' => 'explain', 'text' => 'How do I solve linear equations in general?'])->assertCreated();
        $this->assertCount(1, $this->ai->prompts);
    }

    public function test_requests_to_do_assessed_work_are_refused(): void
    {
        $this->actingAs($this->adult());
        foreach (['Write my essay for me so I can submit it', 'Can you do my assignment for me', 'answers to my exam on Friday', 'I am writing a test right now, what is 7 times 8'] as $text) {
            $this->ask(['task' => 'explain', 'text' => $text])->assertUnprocessable()->assertJsonPath('error', 'integrity_request');
        }
        $this->assertSame([], $this->ai->prompts);
    }

    public function test_contact_details_are_removed_before_sending_and_storing(): void
    {
        $this->actingAs($this->adult());
        $this->ask(['task' => 'explain', 'text' => 'Email me at sipho@example.com or call 082 555 1234 about cells'])->assertCreated();

        $this->assertStringNotContainsString('sipho@example.com', $this->ai->lastText());
        $this->assertStringNotContainsString('082 555 1234', $this->ai->lastText());
        $this->assertStringNotContainsString('sipho@example.com', (string) AiInteraction::sole()->request_text);
    }

    public function test_daily_and_monthly_limits_stop_requests_before_they_are_sent(): void
    {
        config(['ai.limits.learner.adult.daily_requests' => 2]);
        $this->actingAs($this->adult());
        $this->ask(['task' => 'explain', 'text' => 'One'])->assertCreated();
        $this->ask(['task' => 'explain', 'text' => 'Two'])->assertCreated();
        $this->ask(['task' => 'explain', 'text' => 'Three'])->assertStatus(429)->assertJsonPath('error', 'daily_limit');

        config(['ai.limits.monthly_budget_micro_usd' => 1]);
        $this->actingAs($this->adult('Other'));
        $this->ask(['task' => 'explain', 'text' => 'Four'])->assertStatus(429)->assertJsonPath('error', 'monthly_budget');
        $this->assertCount(2, $this->ai->prompts);
    }

    public function test_safeguarding_words_flag_the_interaction_and_add_help_for_a_minor(): void
    {
        $this->actingAs($this->consentedChild());
        $this->ask(['task' => 'explain', 'text' => 'Someone asked me to send a photo of myself and keep it a secret from my parents'])
            ->assertCreated()
            ->assertJsonPath('data.safety_note', fn ($note) => str_contains($note, 'Childline free on 116'));

        $row = AiInteraction::sole();
        $this->assertTrue($row->flagged);
        $this->assertSame('request:danger', $row->flag_reason);
        $this->assertTrue($row->purge_after->greaterThan(now()->addDays(700)), 'Flagged rows are kept as evidence.');
    }

    public function test_contact_details_in_an_answer_never_reach_a_minor(): void
    {
        $this->ai->queue(FakeAiTutor::ok('Message my friend on WhatsApp at +27 82 555 1234 for help.'));
        $this->actingAs($this->consentedChild());
        $this->ask(['task' => 'explain', 'text' => 'Help with fractions'])->assertCreated()
            ->assertJsonPath('data.text', fn ($text) => ! str_contains($text, '555 1234'));
    }

    public function test_the_report_button_files_a_safety_report_and_keeps_the_evidence(): void
    {
        $learner = $this->consentedChild();
        $this->actingAs($learner);
        $id = $this->ask(['task' => 'explain', 'text' => 'Tell me about the moon'])->json('data.id');

        $this->postJson("/api/tutor/ai/interactions/{$id}/report", ['category' => 'misinformation', 'details' => 'The moon is not made of cheese'])->assertCreated();

        $report = Report::sole();
        $this->assertSame((new AiInteraction)->getMorphClass(), $report->reportable_type);
        $this->assertTrue($report->involves_minor);
        $this->assertSame('ai_interaction', $report->snapshot['type']);
        $this->assertNotNull(AiInteraction::find($id)->reported_at);

        $this->actingAs($this->adult('Someone else'));
        $this->postJson("/api/tutor/ai/interactions/{$id}/report", ['category' => 'other'])->assertNotFound();
        $this->getJson("/api/tutor/ai/interactions/{$id}")->assertNotFound();
    }

    public function test_operators_review_the_log_and_every_read_is_audited(): void
    {
        $this->actingAs($this->adult());
        $id = $this->ask(['task' => 'explain', 'text' => 'Explain gravity'])->json('data.id');

        $this->getJson('/api/tutor/ai/operator/log')->assertForbidden();

        $operator = $this->makeOperator();
        $this->actingAs($operator);
        $this->getJson('/api/tutor/ai/operator/log?filter=all')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonMissingPath('data.0.request_text');
        $this->getJson("/api/tutor/ai/interactions/{$id}")->assertOk();
        $this->assertDatabaseHas('safety_audit_log', ['action' => 'ai.interaction.read', 'actor_id' => $operator->id]);
        $this->get('/ai/operator/log')->assertOk()->assertSee('AI safeguarding log');
    }

    public function test_retention_purges_old_rows_and_keeps_the_rest(): void
    {
        $this->actingAs($this->adult());
        $this->ask(['task' => 'explain', 'text' => 'Old question']);
        $this->ask(['task' => 'explain', 'text' => 'New question']);
        AiInteraction::query()->orderBy('id')->first()->forceFill(['purge_after' => now()->subDay()])->save();

        $this->artisan('ai:purge-interactions')->assertSuccessful();
        $this->assertSame(['New question'], AiInteraction::all()->pluck('request_text')->all());
    }

    public function test_the_assistant_and_answer_screens_render(): void
    {
        $this->actingAs($this->adult());
        $id = $this->ask(['task' => 'explain', 'text' => 'What is **energy**?'])->json('data.id');

        $this->get('/ai')->assertOk()->assertSee('Ask the assistant')->assertSee('AI guidance');
        $this->get("/ai/interactions/{$id}")->assertOk()->assertSee('AI guidance')->assertSee('Report this answer')->assertSee('Ask a tutor instead');
        $this->post('/ai/ask', ['task' => 'explain', 'text' => 'Form question'])->assertRedirect();
    }
}
