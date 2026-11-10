<?php

namespace Tests\Feature\Academic;

use App\Domain\Academic\Enums\ReviewStatus;
use App\Domain\Academic\Enums\TranslationProvenance;
use App\Domain\Academic\I18n\NotationGuard;
use App\Domain\Academic\Models\Topic;
use App\Domain\Academic\Translations;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Concerns\SeedsAcademicStructure;
use Tests\TestCase;

class TranslationsTest extends TestCase
{
    use RefreshDatabase;
    use SeedsAcademicStructure;

    private Translations $translations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAcademic();
        $this->translations = app(Translations::class);
    }

    public function test_a_machine_translation_is_stored_with_provenance_and_shown_beside_the_original(): void
    {
        $subject = $this->subject('mathematics');
        $this->translations->put($subject, 'name', 'af', 'Wiskunde', TranslationProvenance::Machine, machineModel: 'test-model-1');

        $view = $this->translations->sideBySide($subject, 'name', 'af');

        $this->assertSame(['locale' => 'en', 'text' => 'Mathematics'], $view['original']);
        $this->assertSame('Wiskunde', $view['translation']['text']);
        $this->assertSame('machine', $view['translation']['provenance']);
        $this->assertSame('Machine translation', $view['translation']['provenance_label']);
        $this->assertSame('test-model-1', $view['translation']['machine_model']);
        $this->assertSame('unreviewed', $view['translation']['review_status']);
        $this->assertFalse($view['translation']['out_of_date']);
        $this->assertSame(['af'], $view['available_locales']);

        $this->assertNull($this->translations->sideBySide($subject, 'name', 'fr')['translation']);
    }

    public function test_provenance_rules_are_enforced(): void
    {
        $subject = $this->subject('mathematics');
        $translator = User::factory()->create();

        $cases = [
            'machine without a model' => fn () => $this->translations->put($subject, 'name', 'af', 'Wiskunde', TranslationProvenance::Machine),
            'human without a translator' => fn () => $this->translations->put($subject, 'name', 'af', 'Wiskunde', TranslationProvenance::Human),
            'into the original language' => fn () => $this->translations->put($subject, 'name', 'en', 'Maths', TranslationProvenance::Human, $translator),
            'a field that is not translatable' => fn () => $this->translations->put($subject, 'slug', 'af', 'wiskunde', TranslationProvenance::Human, $translator),
            'a signed language' => fn () => $this->translations->put($subject, 'name', 'sfs', 'x', TranslationProvenance::Human, $translator),
            'empty text' => fn () => $this->translations->put($subject, 'name', 'af', '  ', TranslationProvenance::Human, $translator),
            'an unregistered model' => fn () => $this->translations->put($translator, 'name', 'af', 'x', TranslationProvenance::Human, $translator),
        ];
        foreach ($cases as $label => $call) {
            try {
                $call();
                $this->fail("Expected a refusal: {$label}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $human = $this->translations->put($subject, 'name', 'af', 'Wiskunde', TranslationProvenance::Human, translatorLabel: 'Example Translation Agency');
        $this->assertSame('Example Translation Agency', $this->translations->sideBySide($subject, 'name', 'af')['translation']['translator']);
        $this->assertNull($human->machine_model);
    }

    public function test_editing_the_original_marks_the_translation_out_of_date_and_new_text_needs_a_new_review(): void
    {
        $subject = $this->subject('history');
        $reviewer = User::factory()->create();
        $translation = $this->translations->put($subject, 'name', 'fr', 'Histoire', TranslationProvenance::Machine, machineModel: 'm');
        $this->translations->review($translation, $reviewer, ReviewStatus::Approved);
        $this->assertSame('approved', $this->translations->sideBySide($subject, 'name', 'fr')['translation']['review_status']);

        $subject->update(['name' => 'History (CAPS)']);
        $this->assertTrue($this->translations->sideBySide($subject->fresh(), 'name', 'fr')['translation']['out_of_date']);

        $this->translations->put($subject->fresh(), 'name', 'fr', 'Histoire (CAPS)', TranslationProvenance::Machine, machineModel: 'm');
        $view = $this->translations->sideBySide($subject->fresh(), 'name', 'fr');
        $this->assertFalse($view['translation']['out_of_date']);
        $this->assertSame('unreviewed', $view['translation']['review_status']);
    }

    public function test_a_rejected_translation_is_not_shown(): void
    {
        $subject = $this->subject('geography');
        $translation = $this->translations->put($subject, 'name', 'pt', 'Geografía', TranslationProvenance::Machine, machineModel: 'm');
        $this->translations->review($translation, User::factory()->create(), ReviewStatus::Rejected);

        $view = $this->translations->sideBySide($subject, 'name', 'pt');
        $this->assertNull($view['translation']);
        $this->assertSame([], $view['available_locales']);
        $this->assertSame('Geography', $view['original']['text'], 'The original is always available');
    }

    public function test_notation_that_does_not_survive_translation_is_flagged(): void
    {
        $original = 'Solve $x^2 - 5x + 6 = 0$ and find f(x)=2x+1 when x = 3.5; water is H2O.';

        $this->assertSame([], NotationGuard::missing($original, 'Los op $x^2 - 5x + 6 = 0$ en vind f(x)=2x+1 as x = 3,5; water is H2O.'));
        $this->assertSame(['$x^2 - 5x + 6 = 0$', 'H2O'], NotationGuard::missing($original, 'Los op x kwadraat, vind f(x)=2x+1 as x = 3.5; water is H twee O.'));

        $this->assertSame([], NotationGuard::tokens('A well-known and/or simple idea.'));
        $this->assertSame(['CO2', '12'], NotationGuard::tokens('Plants take in CO2 for 12 hours.'));

        $topic = Topic::query()->firstOrFail();
        $topic->update(['description' => 'Use a^2 + b^2 = c^2.']);
        $this->translations->put($topic, 'description', 'af', 'Gebruik die stelling van Pythagoras.', TranslationProvenance::Machine, machineModel: 'm');
        $this->assertSame(['a^2 + b^2 = c^2'], $this->translations->sideBySide($topic, 'description', 'af')['translation']['notation_missing']);
    }

    public function test_the_side_by_side_endpoint(): void
    {
        $subject = $this->subject('mathematics');
        $this->translations->put($subject, 'name', 'af', 'Wiskunde', TranslationProvenance::Machine, machineModel: 'm');
        $url = fn (string $type, int $id, string $field, ?string $locale = 'af') => route('academic.translations.show', [$type, $id, $field]).($locale ? "?locale={$locale}" : '');

        $this->getJson($url('academic.subject', $subject->id, 'name'))->assertUnauthorized()->assertJsonPath('error', 'unauthenticated'); // one JSON error policy (D-1404)

        $user = User::factory()->create();
        $this->actingAs($user)->getJson($url('academic.subject', $subject->id, 'name'))
            ->assertOk()->assertJsonPath('original.text', 'Mathematics')->assertJsonPath('translation.text', 'Wiskunde')
            ->assertJsonPath('translation.provenance', 'machine');

        $this->actingAs($user)->getJson($url('academic.subject', $subject->id, 'name', null))->assertStatus(422)->assertJsonPath('error', 'validation_failed');
        $this->actingAs($user)->getJson($url('academic.subject', $subject->id, 'slug'))->assertNotFound()->assertJsonPath('error', 'not_translatable');
        $this->actingAs($user)->getJson($url('academic.subject', 999999, 'name'))->assertNotFound()->assertJsonPath('error', 'not_found');
        $this->actingAs($user)->getJson($url('learning.course', 1, 'title'))->assertNotFound();

        // A type outside the public catalogue must pass its own view policy (none here, so denied).
        $this->translations->register('test.private', User::class, ['name']);
        $this->actingAs($user)->getJson($url('test.private', $user->id, 'name'))->assertForbidden()->assertJsonPath('error', 'forbidden');
    }
}
