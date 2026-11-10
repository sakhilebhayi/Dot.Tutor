<?php

namespace Tests\Feature\Ai;

use App\Domain\Academic\Contracts\LanguageProfile;
use App\Domain\Academic\Enums\LanguageRole;
use App\Domain\Academic\Models\AcademicLevel;
use App\Domain\Academic\Models\Language;
use App\Domain\Academic\Models\Translation;
use App\Domain\Academic\Services\ReferenceDataSync;
use App\Domain\Ai\Models\AiInteraction;
use App\Domain\Learning\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Machine translation with provenance and the notation check; the AI fallback for "describe what you need". */
class TranslationAndNeedTest extends TestCase
{
    use AiFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeAi();
    }

    private function languages(): void
    {
        foreach ([['en', 'English', 1], ['zu', 'isiZulu', 2], ['af', 'Afrikaans', 3]] as [$code, $name, $sort]) {
            Language::query()->firstOrCreate(['code' => $code], ['name_en' => $name, 'name_native' => $name, 'script' => 'Latn', 'modality' => 'written', 'is_official_za' => true, 'is_active' => true, 'sort' => $sort]);
        }
    }

    private function course(User $owner): Course
    {
        return Course::create(['owner_id' => $owner->id, 'slug' => 'q-'.uniqid(), 'title' => 'Quadratics', 'description' => 'Solve $x^2 - 5x + 6 = 0$ and find 2 roots.',
            'original_locale' => 'en', 'pricing' => 'free', 'currency' => 'ZAR', 'status' => 'published', 'published_at' => now()]);
    }

    public function test_a_machine_translation_is_stored_with_provenance_and_shown_beside_the_original(): void
    {
        $this->languages();
        $course = $this->course($this->tutorUser());
        $this->ai->queue(FakeAiTutor::ok('', ['translation' => 'Xazulula $x^2 - 5x + 6 = 0$ bese uthola izimpande ezi-2.']));

        $this->actingAs($this->adult('Reader'));
        $this->postJson('/api/tutor/ai/translations', ['type' => 'learning.course', 'id' => $course->id, 'field' => 'description', 'locale' => 'zu'])
            ->assertCreated()
            ->assertJsonPath('data.original.text', 'Solve $x^2 - 5x + 6 = 0$ and find 2 roots.')
            ->assertJsonPath('data.original.locale', 'en')
            ->assertJsonPath('data.translation.provenance', 'machine')
            ->assertJsonPath('data.translation.machine_model', 'claude-opus-5-5')
            ->assertJsonPath('data.translation.review_status', 'unreviewed');
        $this->assertSame('light', end($this->ai->prompts)->tier);
        $this->assertSame('Solve $x^2 - 5x + 6 = 0$ and find 2 roots.', $course->fresh()->description, 'The original is never overwritten.');

        // A second request reuses the stored translation: no second model call.
        $this->postJson('/api/tutor/ai/translations', ['type' => 'learning.course', 'id' => $course->id, 'field' => 'description', 'locale' => 'zu'])
            ->assertOk()->assertJsonPath('data.reused', true);
        $this->assertCount(1, $this->ai->prompts);

        $this->get("/ai/translations/learning.course/{$course->id}/description?locale=zu")->assertOk()
            ->assertSee('Machine translation by claude-opus-5-5')->assertSee('Solve $x^2', false);
    }

    public function test_a_translation_that_loses_a_formula_or_number_is_rejected_and_not_stored(): void
    {
        $this->languages();
        $course = $this->course($this->tutorUser());
        $this->ai->queue(FakeAiTutor::ok('', ['translation' => 'Xazulula isibalo bese uthola izimpande.']));

        $this->actingAs($this->adult('Reader'));
        $this->postJson('/api/tutor/ai/translations', ['type' => 'learning.course', 'id' => $course->id, 'field' => 'description', 'locale' => 'zu'])
            ->assertUnprocessable()->assertJsonPath('error', 'notation_lost')
            ->assertJsonPath('data.detail', fn ($d) => str_contains($d, '$x^2 - 5x + 6 = 0$'));
        $this->assertSame(0, Translation::count());
        $this->assertSame('notation_lost', AiInteraction::sole()->refusal_code);
    }

    public function test_reviewers_must_be_able_to_read_the_language(): void
    {
        $this->languages();
        $owner = $this->tutorUser();
        $course = $this->course($owner);
        $this->ai->queue(FakeAiTutor::ok('', ['translation' => 'Los $x^2 - 5x + 6 = 0$ op en vind 2 wortels.']));
        $this->actingAs($this->adult('Reader'));
        $this->postJson('/api/tutor/ai/translations', ['type' => 'learning.course', 'id' => $course->id, 'field' => 'description', 'locale' => 'af'])->assertCreated();
        $translation = Translation::sole();

        $this->postJson("/api/tutor/ai/translations/{$translation->id}/review", ['decision' => 'approved'])->assertForbidden();

        $this->actingAs($owner);
        $this->postJson("/api/tutor/ai/translations/{$translation->id}/review", ['decision' => 'approved'])->assertForbidden();
        app(LanguageProfile::class)->sync($owner, LanguageRole::Understands, ['af']);
        $this->postJson("/api/tutor/ai/translations/{$translation->id}/review", ['decision' => 'approved'])->assertOk()->assertJsonPath('data.review_status', 'approved');
        $this->assertDatabaseHas('safety_audit_log', ['action' => 'ai.translation.reviewed', 'actor_id' => $owner->id]);
    }

    public function test_content_you_cannot_see_cannot_be_translated(): void
    {
        $this->languages();
        $course = $this->course($this->tutorUser());
        $course->forceFill(['status' => 'draft'])->save();
        $this->actingAs($this->adult('Reader'));
        $this->postJson('/api/tutor/ai/translations', ['type' => 'learning.course', 'id' => $course->id, 'field' => 'description', 'locale' => 'zu'])->assertNotFound();
        $this->postJson('/api/tutor/ai/translations', ['type' => 'learning.course', 'id' => $course->id, 'field' => 'refund_policy', 'locale' => 'zu'])->assertNotFound();
        $this->assertSame([], $this->ai->prompts);
    }

    public function test_the_parser_answers_alone_when_it_is_sure(): void
    {
        app(ReferenceDataSync::class)->run();
        $this->actingAs($this->adult());
        $this->postJson('/api/tutor/ai/describe', ['text' => 'Grade 11 Mathematics in isiZulu', 'use_ai' => true])
            ->assertOk()->assertJsonPath('data.source', 'parser')->assertJsonPath('data.unsure', false);
        $this->assertSame([], $this->ai->prompts);
    }

    public function test_the_ai_fallback_rewords_an_unclear_need_and_waits_for_confirmation(): void
    {
        app(ReferenceDataSync::class)->run();
        $learner = $this->adult();
        $this->actingAs($learner);
        $text = 'my son is in his second last year of high school and battles with maths, he understands zulu best';
        $this->postJson('/api/tutor/ai/describe', ['text' => $text])->assertOk()->assertJsonPath('data.unsure', true)->assertJsonPath('data.source', 'parser');
        $this->assertSame([], $this->ai->prompts, 'No AI unless the person asks for it.');

        $this->ai->queue(FakeAiTutor::ok('', ['grade_or_level' => 'Grade 11', 'subjects' => ['Mathematics'], 'teaching_languages' => ['isiZulu']]));
        $response = $this->postJson('/api/tutor/ai/describe', ['text' => $text, 'use_ai' => true])->assertOk()
            ->assertJsonPath('data.source', 'ai_assisted')
            ->assertJsonPath('data.needs_confirmation', true)
            ->assertJsonPath('data.normalised', 'Grade 11 Mathematics in isiZulu');
        $result = $response->json('data.result');
        $this->assertNotEmpty($result['levels']);
        $this->assertContains('zu', array_column($result['teaching_languages'], 'code'));
        $interaction = AiInteraction::sole();
        $this->assertSame('awaiting_confirmation', $interaction->status);

        $mathsId = collect($result['subjects'])->firstWhere('name', 'Mathematics')['id'];
        $levelId = $result['levels'][0]['id'];

        $this->actingAs($this->adult('Someone else'));
        $this->postJson("/api/tutor/ai/describe/{$interaction->id}/confirm", ['subject_id' => $mathsId])->assertNotFound();

        $this->actingAs($learner);
        $this->postJson("/api/tutor/ai/describe/{$interaction->id}/confirm", ['subject_id' => 999999])->assertUnprocessable();
        $url = $this->postJson("/api/tutor/ai/describe/{$interaction->id}/confirm", ['subject_id' => $mathsId, 'level_id' => $levelId, 'languages' => ['zu']])
            ->assertOk()->json('data.match_url');
        $this->assertStringContainsString('subject_id='.$mathsId, $url);
        $this->assertStringContainsString('languages=zu', $url);
        $this->assertSame('confirmed', $interaction->fresh()->status);
        $this->assertNotNull(AcademicLevel::find($levelId));
    }

    public function test_the_need_screens_render_and_offer_ai_only_when_unsure(): void
    {
        app(ReferenceDataSync::class)->run();
        $this->actingAs($this->adult());
        $this->get('/ai/describe')->assertOk()->assertSee('Describe what you need');
        $this->post('/ai/describe', ['text' => 'Grade 11 Mathematics in isiZulu'])->assertOk()->assertSee('Yes, find tutors for this')->assertDontSee('Let the AI assistant reword it');
        $this->post('/ai/describe', ['text' => 'my kid hates numbers'])->assertOk()->assertSee('Let the AI assistant reword it');
    }
}
