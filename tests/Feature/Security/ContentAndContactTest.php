<?php

namespace Tests\Feature\Security;

use App\Domain\Marketplace\Services\TutorPresenter;
use App\Domain\Safety\Services\ContactRedactor;
use App\Models\TutorSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Learning\LearningFixtures;
use Tests\Feature\Safety\BuildsSafetyState;
use Tests\TestCase;

/**
 * R-47 security S-08, S-14, S-15, S-17, S-18: contact details reaching minors through free text, stored links,
 * and an answer key that could be harvested for a certificate.
 */
class ContentAndContactTest extends TestCase
{
    use BuildsSafetyState, LearningFixtures {
        BuildsSafetyState::operator insteadof LearningFixtures;
        LearningFixtures::operator as learningOperator;
    }
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('learning');
    }

    public function test_a_tutor_bio_hides_contact_details_from_a_minor_but_not_from_an_adult(): void
    {
        $tutorUser = $this->adult('Tutor');
        $profile = $this->makeTutor(['bio' => 'Twenty years of maths. WhatsApp me on 082 123 4567.'], null, $tutorUser);
        $child = $this->child();
        $this->linkGuardian($child);

        $this->actingAs($child);
        $this->assertStringNotContainsString('082 123 4567', app(TutorPresenter::class)->storefront($profile)['introduction']);
        $this->get(route('tutors.show', $profile))->assertDontSee('082 123 4567');

        $this->actingAs($this->adult('Adult learner'));
        $this->assertStringContainsString('082 123 4567', app(TutorPresenter::class)->storefront($profile)['introduction']);
    }

    public function test_booking_notes_from_a_minor_are_stored_without_contact_details(): void
    {
        $child = $this->child();
        $this->linkGuardian($child);
        $profile = $this->makeTutor();
        $this->screen($profile->user);
        $session = $this->makeSession($profile, $child, $this->makeSubject('Maths'), ['notes' => 'Help with fractions, my number is 082 123 4567']);

        $this->assertStringNotContainsString('082 123 4567', TutorSession::findOrFail($session->id)->notes);
        $this->assertStringContainsString('fractions', TutorSession::findOrFail($session->id)->notes);
    }

    public function test_the_redactor_sees_through_look_alike_characters(): void
    {
        $redactor = app(ContactRedactor::class);

        foreach (['call ０８２ １２３ ４５６７', "0\u{200B}82 123 4567", 'call 082/123/4567', 'mail ali＠gmail.com', 'my insta is coolkid', 'bit.ly/abcd', '٠٨٢١٢٣٤٥٦٧'] as $text) {
            $this->assertNotSame([], $redactor->redact($text)['redactions'], $text);
        }

        $this->assertSame([], $redactor->redact('x = 1,000,000,000 and pi is 3.14159')['redactions'], 'Ordinary maths stays');
    }

    public function test_a_library_source_link_must_be_https(): void
    {
        $this->actingAs($this->learner());

        $this->post('/api/tutor/learning/library', [
            'title' => 'Notes', 'description' => 'Notes on algebra and functions.', 'type' => 'summary', 'country_code' => 'za',
            'subject_id' => $this->subject()->id, 'topic' => 'Algebra', 'language' => 'en', 'difficulty' => 'standard',
            'ownership' => 'own_work', 'licence' => 'cc_by', 'rights_confirmed' => '1', 'file' => $this->pdfUpload(),
            'source_url' => 'javascript:alert(document.cookie)',
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('source_url');
    }

    public function test_an_assessed_quiz_with_unlimited_tries_does_not_hand_out_its_key_before_a_pass(): void
    {
        $tutor = $this->approvedTutor();
        $this->actingAs($tutor);
        $bank = $this->postJson('/api/tutor/learning/banks', ['title' => 'Roots', 'topic' => 'Roots', 'strand' => 'Algebra', 'subject_id' => $this->subject()->id])->json('data.id');
        $question = $this->postJson("/api/tutor/learning/banks/{$bank}/questions", [
            'type' => 'single_choice', 'prompt' => 'Roots of x^2 - 5x + 6 = 0?',
            'options' => [['key' => 'a', 'text' => '2 and 3'], ['key' => 'b', 'text' => '-2 and -3']],
            'answer' => ['key' => 'a'], 'explanation' => 'Factorise: (x - 2)(x - 3) = 0',
        ])->assertCreated()->json('data.id');
        $assessment = $this->postJson('/api/tutor/learning/assessments', ['title' => 'Check', 'kind' => 'tutor_assessment', 'pass_percent' => 60])->assertCreated()->json('data.id');
        $this->postJson("/api/tutor/learning/assessments/{$assessment}/questions", ['question_ids' => [$question]])->assertOk();
        $learner = $this->learner();
        TutorSession::create([
            'tutor_profile_id' => $tutor->tutorProfile->id, 'student_id' => $learner->id, 'subject_id' => $this->subject()->id,
            'status' => 'completed', 'starts_at' => now()->subDay(), 'duration_minutes' => 60, 'rate' => 200,
        ]);
        $this->postJson("/api/tutor/learning/assessments/{$assessment}/assign", ['learner_id' => $learner->id])->assertCreated();

        $this->actingAs($learner);
        $first = $this->postJson("/api/tutor/learning/assessments/{$assessment}/attempts")->json('data.attempt_id');
        $empty = $this->postJson("/api/tutor/learning/attempts/{$first}/submit", ['answers' => [$question => 'b']])->assertOk();
        $this->assertStringNotContainsString('Factorise', $empty->getContent());
        $this->assertArrayNotHasKey('answer_key', $empty->json('data.questions.0'));
        $this->assertFalse($empty->json('data.questions.0.correct'));

        $second = $this->postJson("/api/tutor/learning/assessments/{$assessment}/attempts")->json('data.attempt_id');
        $passed = $this->postJson("/api/tutor/learning/attempts/{$second}/submit", ['answers' => [$question => 'a']])->assertOk();
        $this->assertStringContainsString('Factorise', $passed->getContent(), 'Once passed, the explanation is shown');
    }
}
